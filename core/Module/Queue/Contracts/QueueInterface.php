<?php
declare(strict_types=1);

namespace App\Core\Module\Queue\Contracts;

/**
 * Контракт модуля Queue — фоновые задачи (push/worker).
 * Единственное, что видят другие модули (п.2 Конституции).
 */
interface QueueInterface
{
    /** Поставить задачу в очередь; $delay — секунд до готовности. Возвращает id. */
    public function push(JobInterface $job, int $delay = 0): string;

    /** Взять следующую готовую задачу (или null, если пусто). */
    public function pop(): ?JobInterface;

    /** Отметить задачу выполненной. */
    public function ack(string $jobId): bool;

    /** Вернуть задачу в очередь с задержкой (retry). */
    public function retry(string $jobId, int $delaySeconds = 60): bool;

    /** Задача провалена навсегда: переносится в failed/. */
    public function fail(string $jobId, \Throwable $error): bool;

    /** Количество готовых задач (для Doctor). */
    public function size(): int;

    /** Активный драйвер: file|redis (для Doctor). */
    public function driver(): string;
}
