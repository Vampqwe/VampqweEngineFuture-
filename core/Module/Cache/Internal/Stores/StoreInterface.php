<?php
declare(strict_types=1);

namespace App\Core\Module\Cache\Internal\Stores;

/**
 * Внутренний контракт стора кэша (п.2 Конституции: только для модуля Cache).
 * Реализации: FileStore, ArrayStore, RedisStore.
 */
interface StoreInterface
{
    /** @return mixed null — если ключа нет или TTL истёк. */
    public function read(string $key): mixed;

    /** @param int $ttl секунд; 0 = бессрочно. */
    public function write(string $key, mixed $value, int $ttl): bool;

    public function exists(string $key): bool;

    public function delete(string $key): bool;

    public function clear(): bool;

    /** Атомарный инкремент; false — если ключ отсутствует. */
    public function increment(string $key, int $by): int|false;
}
