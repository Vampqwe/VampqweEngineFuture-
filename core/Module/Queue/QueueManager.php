<?php
declare(strict_types=1);

namespace App\Core\Module\Queue;

use App\Core\Module\Queue\Contracts\JobInterface;
use App\Core\Module\Queue\Contracts\QueueInterface;
use App\Core\Module\Queue\Internal\Drivers\DriverInterface;
use App\Core\Module\Queue\Internal\Drivers\FileQueueDriver;
use App\Core\Module\Queue\Internal\Drivers\RedisQueueDriver;
use RuntimeException;

/**
 * Фасад модуля Queue — менеджер драйверов: file (по умолчанию) | redis.
 * Публичная граница — QueueInterface. Пути из Paths (queue_path).
 * Redis при недоступности деградирует в file (fail-safe, с логом канала Queue).
 */
final class QueueManager implements QueueInterface
{
    public function __construct(
        private readonly DriverInterface $driver,
        private readonly string $driverName = 'file',
        private readonly int $maxAttempts = 3,
        private readonly int $retryAfter = 60,
        private readonly int $visibility = 30,
    ) {
    }

    /** Factory из конфигурации — вызывается только провайдером. */
    public static function fromConfig(
        string $driver,
        array $backends,
        int $maxAttempts,
        int $retryAfter,
        int $visibility,
        ?callable $logger = null,
    ): self {
        $driver = strtolower($driver);

        if ($driver === 'redis') {
            $cfg = $backends['redis'] ?? [];
            if (($cfg['enabled'] ?? false) === true) {
                $rd = new RedisQueueDriver(
                    (string) ($cfg['list'] ?? 'engine:queue'),
                    (string) ($cfg['host'] ?? '127.0.0.1'),
                    (int) ($cfg['port'] ?? 6379),
                    $cfg['password'] ?? null,
                    (int) ($cfg['database'] ?? 0),
                );
                if ($rd->isAvailable()) {
                    return new self($rd, 'redis', $maxAttempts, $retryAfter, $visibility);
                }
                $logger?->warning('Queue: redis недоступен, деградация в file-бэкенд.');
            } else {
                $logger?->notice('Queue: driver=redis, но redis.enabled=false — используется file.');
            }
            $driver = 'file';
        }

        if ($driver !== 'file') {
            throw new RuntimeException("Queue: неизвестный драйвер [{$driver}] (ожидался file|redis).");
        }

        $path = (string) ($backends['file']['path'] ?? '');
        if ($path === '') {
            throw new RuntimeException('Queue: не задан путь file-бэкенда (см. config/queue.php и Paths).');
        }
        return new self(new FileQueueDriver($path), 'file', $maxAttempts, $retryAfter, $visibility);
    }

    public function driver(): string
    {
        return $this->driverName;
    }

    public function push(JobInterface $job, int $delay = 0): string
    {
        return $this->driver->push($job, max(0, $delay));
    }

    public function pop(): ?JobInterface
    {
        $reserved = $this->driver->reserve($this->visibility);
        return $reserved === null ? null : $reserved[0];
    }

    /**
     * Полный цикл обработки одной задачи (использует worker):
     * reserve -> handle -> ack | retry | fail.
     * @return string|null статус: processed|retried|failed|null(пусто)
     */
    public function processNext(?callable $log = null): ?string
    {
        $reserved = $this->driver->reserve($this->visibility);
        if ($reserved === null) {
            return null;
        }
        [$job, $payloadId] = $reserved;

        try {
            $job->handle();
            $this->driver->ack($payloadId);
            $log?->info('Queue: задача выполнена', ['job' => $job->name(), 'id' => $payloadId]);
            return 'processed';
        } catch (\Throwable $e) {
            $attempts = $job->attempts();
            if ($attempts >= $this->maxAttempts) {
                $job->fail($e);
                $this->driver->toFailed($payloadId, $e::class . ': ' . $e->getMessage());
                $log?->error('Queue: задача провалена навсегда', [
                    'job' => $job->name(), 'id' => $payloadId, 'error' => $e->getMessage(),
                ]);
                return 'failed';
            }
            $this->driver->release($payloadId, min($this->retryAfter * $attempts, 3600));
            $log?->warning('Queue: задача переотправлена (retry)', [
                'job' => $job->name(), 'id' => $payloadId, 'attempt' => $attempts,
                'error' => $e->getMessage(),
            ]);
            return 'retried';
        }
    }

    public function ack(string $jobId): bool
    {
        return $this->driver->ack($jobId);
    }

    public function retry(string $jobId, int $delaySeconds = 60): bool
    {
        return $this->driver->release($jobId, max(0, $delaySeconds));
    }

    public function fail(string $jobId, \Throwable $error): bool
    {
        return $this->driver->toFailed($jobId, $error::class . ': ' . $error->getMessage());
    }

    public function size(): int
    {
        return $this->driver->size();
    }
}
