<?php
declare(strict_types=1);

namespace App\Core\Module\Cache\Contracts;

/**
 * Контракт модуля Cache — хранилище «быстрых пар» key => value с TTL.
 * Единственное, что видят другие модули (п.2 Конституции).
 */
interface CacheInterface
{
    /** Получить значение по ключу; null если нет или истёк TTL. */
    public function get(string $key, mixed $default = null): mixed;

    /** Записать значение на $ttl секунд (0 = бессрочно). */
    public function set(string $key, mixed $value, int $ttl = 0): bool;

    /** Есть ли ключ (и не истёк ли). */
    public function has(string $key): bool;

    /** Удалить ключ. */
    public function forget(string $key): bool;

    /** Полностью очистить кэш. */
    public function flush(): bool;

    /** Получить или вычислить и закешировать (callback-мемоизация). */
    public function remember(string $key, int $ttl, callable $callback): mixed;
}
