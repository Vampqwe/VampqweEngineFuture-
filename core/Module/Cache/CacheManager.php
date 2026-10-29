<?php
declare(strict_types=1);

namespace App\Core\Module\Cache;

use App\Core\Module\Cache\Contracts\CacheInterface;
use App\Core\Module\Cache\Internal\Stores\ArrayStore;
use App\Core\Module\Cache\Internal\Stores\FileStore;
use App\Core\Module\Cache\Internal\Stores\RedisStore;
use App\Core\Module\Cache\Internal\Stores\StoreInterface;
use RuntimeException;

/**
 * Фасад модуля Cache — менеджер драйверов: file (по умолчанию) | array | redis.
 *
 * Публичная граница модуля — только CacheInterface (п.2 Конституции).
 * Пути сторов приходят из ЕДИНОЙ точки правды Paths (cache_path), не хардкодом.
 * Redis-стор при недоступности деградирует в file с записью в лог канала Cache.
 */
final class CacheManager implements CacheInterface
{
    public function __construct(
        private readonly StoreInterface $store,
        private readonly string $driver = 'file',
    ) {
    }

    /** Factory из конфигурации — вызывается только провайдером (п.3: new в провайдерах). */
    public static function fromConfig(
        string $driver,
        string $prefix,
        array $stores,
        ?callable $logger = null,
    ): self {
        $driver = strtolower($driver);

        if ($driver === 'redis') {
            $cfg = $stores['redis'] ?? [];
            if (($cfg['enabled'] ?? false) === true) {
                $store = new RedisStore(
                    $prefix,
                    (string) ($cfg['host'] ?? '127.0.0.1'),
                    (int) ($cfg['port'] ?? 6379),
                    $cfg['password'] ?? null,
                    (int) ($cfg['database'] ?? 0),
                );
                if ($store->isAvailable()) {
                    return new self($store, 'redis');
                }
                // Fail-safe: redis объявлен, но недоступен — не роняем движок.
                $logger?->warning('Cache: redis недоступен, деградация в file-стор.');
            } else {
                $logger?->notice('Cache: driver=redis, но redis.enabled=false — используется file.');
            }
            $driver = 'file';
        }

        if ($driver === 'array') {
            return new self(new ArrayStore(), 'array');
        }

        if ($driver !== 'file') {
            throw new RuntimeException("Cache: неизвестный драйвер [{$driver}] (ожидался file|array|redis).");
        }

        $path = (string) ($stores['file']['path'] ?? '');
        if ($path === '') {
            throw new RuntimeException('Cache: не задан путь file-стора (см. config/cache.php и Paths).');
        }
        return new self(new FileStore($path), 'file');
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->store->read($key);
        return $value === null && !$this->store->exists($key) ? $default : $value;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        return $this->store->write($key, $value, max(0, $ttl));
    }

    public function has(string $key): bool
    {
        return $this->store->exists($key);
    }

    public function forget(string $key): bool
    {
        return $this->store->delete($key);
    }

    public function flush(): bool
    {
        return $this->store->clear();
    }

    public function inc(string $key, int $by = 1): int|false
    {
        return $this->store->increment($key, $by);
    }

    public function dec(string $key, int $by = 1): int|false
    {
        return $this->store->increment($key, -$by);
    }

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        if ($this->store->exists($key)) {
            $hit = $this->store->read($key);
            if ($hit !== null) {
                return $hit;
            }
        }
        $value = $callback();
        $this->set($key, $value, $ttl);
        return $value;
    }
}
