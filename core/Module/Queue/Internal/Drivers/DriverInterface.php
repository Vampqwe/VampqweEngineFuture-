<?php
declare(strict_types=1);

namespace App\Core\Module\Queue\Internal\Drivers;

use App\Core\Module\Queue\Contracts\JobInterface;

/**
 * Внутренний контракт бэкенда очереди (п.2: только для модуля Queue).
 * Реализации: FileQueueDriver, RedisQueueDriver.
 */
interface DriverInterface
{
    public function push(JobInterface $job, int $delaySeconds): string;

    /** @return array{0:JobInterface,1:string}|null [job, payloadId] */
    public function reserve(int $visibilitySeconds): ?array;

    public function ack(string $payloadId): bool;

    public function release(string $payloadId, int $delaySeconds): bool;

    public function toFailed(string $payloadId, string $error): bool;

    public function size(): int;

    public function isAvailable(): bool;
}
