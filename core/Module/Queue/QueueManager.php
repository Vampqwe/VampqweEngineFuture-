<?php
declare(strict_types=1);

namespace App\Core\Module\Queue;

use App\Core\Module\Queue\Contracts\JobInterface;
use App\Core\Module\Queue\Contracts\QueueInterface;
use RuntimeException;

/**
 * Фасад модуля Queue (менеджер драйверов: file|database|redis).
 *
 * Зависимости: Config (queue.driver), FileSystem, Parser (сериализация), TimeDate (delay).
 * РЕАЛИЗАЦИЯ В ПЛАНЕ.
 */
final class QueueManager implements QueueInterface
{
    public function __construct(private readonly string $driver = 'file')
    {
    }

    public function push(JobInterface $job, int $delay = 0): string
    {
        throw new RuntimeException('Модуль [Queue] объявлен в ядре, но ещё не реализован.');
    }

    public function pop(): ?JobInterface
    {
        throw new RuntimeException('Модуль [Queue] ещё не реализован.');
    }

    public function ack(string $jobId): bool
    {
        throw new RuntimeException('Модуль [Queue] ещё не реализован.');
    }

    public function retry(string $jobId, int $delaySeconds = 60): bool
    {
        throw new RuntimeException('Модуль [Queue] ещё не реализован.');
    }

    public function size(): int
    {
        return 0; // честно: задач нет, потому что очереди ещё нет
    }
}
