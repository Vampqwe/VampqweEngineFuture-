<?php
declare(strict_types=1);

namespace App\Core\Module\Queue\Internal;

use App\Core\Module\Hook\Contracts\HookInterface;
use App\Core\Module\Logger\Contracts\LoggerInterface;
use App\Core\Module\Queue\QueueManager;

/**
 * Внутренний worker очереди. ЗАПУСКАЕТСЯ ТОЛЬКО из bin/engine (CLI),
 * запрещён из веб-контекста (п.2 Конституции: Internal не трогают снаружи).
 *
 * Цикл: processNext() -> hook queue.job.processed/retried/failed -> лог канала Queue.
 * Остановка: SIGINT/SIGTERM (если доступна pcntl) или лимит итераций/времени.
 */
final class Worker
{
    public function __construct(
        private readonly QueueManager $queue,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?HookInterface $hooks = null,
    ) {
    }

    /**
     * @param int|null $maxJobs  обработать N задач и выйти (null — пока есть задачи)
     * @param int      $maxSeconds общий лимит времени работы
     * @return array{processed:int,retried:int,failed:int,idle:int}
     */
    public function run(?int $maxJobs = null, int $maxSeconds = 60): array
    {
        $stats = ['processed' => 0, 'retried' => 0, 'failed' => 0, 'idle' => 0];
        $deadline = time() + max(1, $maxSeconds);
        $stop = false;

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            $handler = static function () use (&$stop): void { $stop = true; };
            foreach ([SIGINT, SIGTERM] as $sig) {
                if (defined($sname = 'SIG' . self::sigName($sig))) {
                    pcntl_signal(constant($sname), $handler);
                }
            }
        }

        while (!$stop && ($maxJobs === null || array_sum([$stats['processed'], $stats['failed']]) < $maxJobs)) {
            if (time() >= $deadline) {
                break;
            }
            $log = $this->logger;
            $status = $this->queue->processNext($log === null ? null : static function (string $level, string $msg, array $ctx = []) use ($log): void {
                $ctx['channel'] = 'Queue';
                $log->{$level}($msg, $ctx);
            });

            if ($status === null) {
                $stats['idle']++;
                usleep(500_000); // очередь пуста — короткая пауза вместо busy-loop
                continue;
            }

            $stats[$status]++;
            $this->hooks?->dispatch('queue.job.' . $status, $stats);
        }

        return $stats;
    }

    private static function sigName(int $sig): string
    {
        return match ($sig) {
            2 => 'INT',
            15 => 'TERM',
            default => 'UNKNOWN',
        };
    }
}
