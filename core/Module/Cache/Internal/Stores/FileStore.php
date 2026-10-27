<?php
declare(strict_types=1);

namespace App\Core\Module\Cache\Internal\Stores;

/**
 * Внутренний файловый стор кэша. ЗАПРЕЩЁН для прямого вызова извне модуля (п.2).
 *
 * Layout: <directory>/<hh>/<sha1(key)>.cache — шардинг по первым 2 hex hash,
 * чтобы в одной папке не лежали десятки тысяч файлов.
 * Формат: первая строка — TTL (unixtime или 0=бессрочно), затем "\n" + serialize(value).
 * Запись атомарная (tmp + rename). Блокировки flock при чтении.
 */
final class FileStore implements StoreInterface
{
    /** @var callable():int */
    private $timeFn;

    public function __construct(private readonly string $directory, ?callable $timeFn = null)
    {
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException("Cache: нельзя создать каталог {$directory}");
        }
        $this->timeFn = $timeFn ?? static fn(): int => time();
    }

    public function read(string $key): mixed
    {
        $file = $this->path($key);
        if (!is_file($file)) {
            return null;
        }
        $fh = @fopen($file, 'rb');
        if ($fh === false) {
            return null;
        }
        flock($fh, LOCK_SH);
        $raw = stream_get_contents($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
        if ($raw === false || !is_string($raw) || !str_contains($raw, "\n")) {
            return null;
        }
        [$ttlLine, $payload] = explode("\n", $raw, 2);
        $expires = (int) $ttlLine;
        if ($expires !== 0 && $expires < ($this->timeFn)()) {
            @unlink($file); // просрочено — чистим проактивно
            return null;
        }
        return unserialize($payload);
    }

    public function write(string $key, mixed $value, int $ttl): bool
    {
        $file    = $this->path($key);
        $dir     = dirname($file);
        $expires = $ttl > 0 ? ($this->timeFn)() + $ttl : 0;
        $raw     = $expires . "\n" . serialize($value);
        $tmp     = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        if (@file_put_contents($tmp, $raw, LOCK_EX) === false) {
            return false;
        }
        if (!rename($tmp, $file)) { // атомарная публикация
            @unlink($tmp);
            return false;
        }
        return true;
    }

    public function exists(string $key): bool
    {
        $file = $this->path($key);
        if (!is_file($file)) {
            return false;
        }
        $raw = @file_get_contents($file);
        if (!is_string($raw) || !str_contains($raw, "\n")) {
            return false;
        }
        [$ttlLine, $payload] = explode("\n", $raw, 2);
        $expires = (int) $ttlLine;
        if ($expires !== 0 && $expires < ($this->timeFn)()) {
            @unlink($file);
            return false;
        }
        // Отличаем «закешировано null» от «ключа нет»: read() вернул бы null в обоих случаях.
        return unserialize($payload) !== null || $payload === serialize(null);
    }

    public function delete(string $key): bool
    {
        $file = $this->path($key);
        return !is_file($file) || @unlink($file);
    }

    public function clear(): bool
    {
        $ok = true;
        foreach (glob($this->directory . '/*/*.cache') ?: [] as $f) {
            $ok = @unlink($f) && $ok;
        }
        foreach (glob($this->directory . '/*/*.tmp') ?: [] as $f) {
            $ok = @unlink($f) && $ok;
        }
        return $ok;
    }

    public function increment(string $key, int $by): int|false
    {
        $current = $this->read($key);
        if ($current === null || !is_int($current)) {
            return false;
        }
        $next = $current + $by;
        return $this->write($key, $next, $this->remainingTtl($key)) ? $next : false;
    }

    /** Остаток TTL ключа в секундах (0 = бессрочно). */
    private function remainingTtl(string $key): int
    {
        $file = $this->path($key);
        if (!is_file($file)) {
            return 0;
        }
        $first = @file_get_contents($file, false, null, 0, 16);
        $expires = is_string($first) ? (int) explode("\n", $first, 2)[0] : 0;
        if ($expires === 0) {
            return 0;
        }
        return max(0, $expires - ($this->timeFn)());
    }

    private function path(string $key): string
    {
        $hash = sha1($key);
        return $this->directory . '/' . substr($hash, 0, 2) . '/' . $hash . '.cache';
    }
}
