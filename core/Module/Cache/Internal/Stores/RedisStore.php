<?php
declare(strict_types=1);

namespace App\Core\Module\Cache\Internal\Stores;

use RuntimeException;

/**
 * Внутренний Redis-стор (driver=redis). Требует расширение ext-redis.
 * ЗАПРЕЩЁН для прямого вызова извне модуля Cache (п.2 Конституции).
 *
 * Подключение ленивое: конструктор ничего не трогает, ping() проверяет доступность,
 * и только первый реальный запрос открывает соединение.
 */
final class RedisStore implements StoreInterface
{
    private ?\Redis $redis = null;

    public function __construct(
        private readonly string $prefix,
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 6379,
        private readonly ?string $password = null,
        private readonly int $database = 0,
        private readonly int $timeoutMs = 1500,
    ) {
    }

    public function isAvailable(): bool
    {
        if (!extension_loaded('redis')) {
            return false;
        }
        try {
            return (bool) $this->conn()->ping();
        } catch (\Throwable) {
            return false;
        }
    }

    public function read(string $key): mixed
    {
        $raw = $this->conn()->get($this->k($key));
        if ($raw === false || $raw === null) {
            return null;
        }
        return unserialize((string) $raw);
    }

    public function write(string $key, mixed $value, int $ttl): bool
    {
        $payload = serialize($value);
        if ($ttl > 0) {
            return (bool) $this->conn()->setex($this->k($key), $ttl, $payload);
        }
        return (bool) $this->conn()->set($this->k($key), $payload);
    }

    public function exists(string $key): bool
    {
        return (bool) $this->conn()->exists($this->k($key));
    }

    public function delete(string $key): bool
    {
        return $this->conn()->del($this->k($key)) >= 0;
    }

    public function clear(): bool
    {
        $keys = $this->conn()->keys($this->prefix . ':*');
        if (is_array($keys) && $keys !== []) {
            $this->conn()->del($keys);
        }
        return true;
    }

    public function increment(string $key, int $by): int|false
    {
        $v = $this->conn()->incrBy($this->k($key), $by);
        return $v === false ? false : (int) $v;
    }

    private function k(string $key): string
    {
        return $this->prefix . ':' . $key;
    }

    private function conn(): \Redis
    {
        if ($this->redis instanceof \Redis) {
            return $this->redis;
        }
        if (!extension_loaded('redis')) {
            throw new RuntimeException('Cache[redis]: расширение ext-redis не установлено.');
        }
        $redis = new \Redis();
        if (!$redis->connect($this->host, $this->port, $this->timeoutMs / 1000)) {
            throw new RuntimeException("Cache[redis]: соединение с {$this->host}:{$this->port} недоступно.");
        }
        if ($this->password !== null && $this->password !== '') {
            $redis->auth($this->password);
        }
        if ($this->database !== 0) {
            $redis->select($this->database);
        }
        return $this->redis = $redis;
    }
}
