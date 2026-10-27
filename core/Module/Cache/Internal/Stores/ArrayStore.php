<?php
declare(strict_types=1);

namespace App\Core\Module\Cache\Internal\Stores;

/**
 * In-memory стор (driver=array): только для тестов и CLI-однократок.
 * Персистентности нет — данные живут ровно время процесса.
 */
final class ArrayStore implements StoreInterface
{
    /** @var array<string, array{value:mixed, expires:int}> */
    private array $items = [];

    public function read(string $key): mixed
    {
        $item = $this->items[$key] ?? null;
        if ($item === null) {
            return null;
        }
        if ($item['expires'] !== 0 && $item['expires'] < time()) {
            unset($this->items[$key]);
            return null;
        }
        return $item['value'];
    }

    public function write(string $key, mixed $value, int $ttl): bool
    {
        $this->items[$key] = ['value' => $value, 'expires' => $ttl > 0 ? time() + $ttl : 0];
        return true;
    }

    public function exists(string $key): bool
    {
        return array_key_exists($key, $this->items) && $this->read($key) !== null
            || (array_key_exists($key, $this->items) && $this->items[$key]['value'] === null);
    }

    public function delete(string $key): bool
    {
        unset($this->items[$key]);
        return true;
    }

    public function clear(): bool
    {
        $this->items = [];
        return true;
    }

    public function increment(string $key, int $by): int|false
    {
        $cur = $this->read($key);
        if (!is_int($cur)) {
            return false;
        }
        $this->write($key, $cur + $by, $this->ttlLeft($key));
        return $cur + $by;
    }

    private function ttlLeft(string $key): int
    {
        $exp = $this->items[$key]['expires'] ?? 0;
        return $exp === 0 ? 0 : max(0, $exp - time());
    }
}
