<?php
declare(strict_types=1);

namespace App\Core\Module\Cache;

use App\Core\Module\Cache\Contracts\CacheInterface;
use RuntimeException;

/**
 * Фасад модуля Cache (менеджер драйверов: file|redis|array).
 *
 * Зависимости: Config (cache.driver), FileSystem (для file-стора), TimeDate (TTL).
 * РЕАЛИЗАЦИЯ В ПЛАНЕ: методы бросают понятную заглушку, а не фатал.
 */
final class CacheManager implements CacheInterface
{
    public function __construct(private readonly string $driver = 'file')
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        throw new RuntimeException('Модуль [Cache] объявлен в ядре, но ещё не реализован (драйвер: ' . $this->driver . ').');
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        throw new RuntimeException('Модуль [Cache] ещё не реализован.');
    }

    public function has(string $key): bool
    {
        throw new RuntimeException('Модуль [Cache] ещё не реализован.');
    }

    public function forget(string $key): bool
    {
        throw new RuntimeException('Модуль [Cache] ещё не реализован.');
    }

    public function flush(): bool
    {
        throw new RuntimeException('Модуль [Cache] ещё не реализован.');
    }

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        return $callback(); // безопасная деградация: без кэша, но и без падения
    }
}
