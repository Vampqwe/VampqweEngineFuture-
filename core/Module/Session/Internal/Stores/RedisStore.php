<?php
declare(strict_types=1);

namespace App\Core\Module\Session\Internal\Stores;

use App\Core\System\Support\SessionStoreInterface;

/**
 * Redis-стор сессий: ключ engine:sess:<id>, значение — JSON, TTL через EXPIRE.
 * Внутренняя кухня модуля Session (п.2 Конституции).
 * Работает поверх расширения ext-redis; при его отсутствии isAvailable()=false
 * и модуль прозрачно деградирует на file-стор.
 */
final class RedisStore implements SessionStoreInterface
{
    private const PREFIX = 'engine:sess:';

    /** @var object|null \Redis */
    private mixed $redis = null;

    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 6379,
        private readonly string $password = '',
        private readonly int $database = 0
    ) {
    }

    public function isAvailable(): bool
    {
        try {
            return $this->connection() !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    public function read(string $id): ?array
    {
        $raw = $this->call(fn(object $r): string|false => $r->get(self::PREFIX . $id));
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function write(string $id, array $data, int $ttlSeconds): bool
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }

        return $this->call(
            fn(object $r): bool => (bool) $r->setex(self::PREFIX . $id, max(60, $ttlSeconds), $json)
        ) === true;
    }

    public function destroy(string $id): bool
    {
        return $this->call(fn(object $r): bool => $r->del(self::PREFIX . $id) !== false) === true;
    }

    public function all(): array
    {
        $out = [];
        $keys = $this->call(fn(object $r): array => iterator_to_array($r->keys(self::PREFIX . '*'))) ?? [];
        foreach ($keys as $key) {
            $id   = substr((string) $key, strlen(self::PREFIX));
            $data = $this->read($id);
            if ($data !== null) {
                $out[$id] = $data;
            }
        }

        return $out;
    }

    /** @template T @param callable(object):T $fn @return T|null */
    private function call(callable $fn): mixed
    {
        $redis = $this->connection();
        if ($redis === null) {
            return null;
        }
        try {
            return $fn($redis);
        } catch (\Throwable) {
            $this->redis = null; // соединение протухо — переподключимся в следующий раз
            return null;
        }
    }

    private function connection(): ?object
    {
        if ($this->redis !== null) {
            return $this->redis;
        }
        if (!class_exists(\Redis::class)) {
            return null;
        }
        $redis = new \Redis();
        if (!@$redis->connect($this->host, $this->port, 1.5)) {
            return null;
        }
        if ($this->password !== '') {
            $redis->auth($this->password);
        }
        if ($this->database !== 0) {
            $redis->select($this->database);
        }
        $this->redis = $redis;

        return $redis;
    }
}
