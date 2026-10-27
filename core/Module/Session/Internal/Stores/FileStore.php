<?php
declare(strict_types=1);

namespace App\Core\Module\Session\Internal\Stores;

use App\Core\System\Support\SessionStoreInterface;

/**
 * Файловый стор сессий: storage/sessions/<id>.sess — PHP-массив через var_export.
 * Внутренняя кухня модуля Session (п.2 Конституции).
 */
final class FileStore implements SessionStoreInterface
{
    public function __construct(private readonly string $directory)
    {
    }

    public function read(string $id): ?array
    {
        if (!$this->valid($id)) {
            return null;
        }
        $file = $this->path($id);
        if (!is_file($file)) {
            return null;
        }

        $payload = @include $file; // var_export-файл, доверенное содержимое нашего стора
        if (!is_array($payload) || !isset($payload['expires'], $payload['data']) || !is_array($payload['data'])) {
            return null;
        }
        if ($payload['expires'] < time()) {
            @unlink($file);
            return null;
        }

        return $payload['data'];
    }

    public function write(string $id, array $data, int $ttlSeconds): bool
    {
        if (!$this->valid($id)) {
            return false;
        }
        $dir = $this->directory;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        $payload = [
            'expires' => time() + max(60, $ttlSeconds),
            'data'    => $data,
        ];

        $tmp = $this->path($id) . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, "<?php\nreturn " . var_export($payload, true) . ';', LOCK_EX) === false) {
            return false;
        }

        return @rename($tmp, $this->path($id));
    }

    public function destroy(string $id): bool
    {
        if (!$this->valid($id)) {
            return false;
        }
        $file = $this->path($id);

        return is_file($file) ? @unlink($file) : true;
    }

    public function all(): array
    {
        $out = [];
        foreach ((array) glob($this->directory . '/*.sess') ?: [] as $file) {
            $id = basename((string) $file, '.sess');
            $data = $this->read($id);
            if ($data !== null) {
                $out[$id] = $data;
            }
        }

        return $out;
    }

    public function isAvailable(): bool
    {
        return is_dir($this->directory) || @mkdir($this->directory, 0775, true);
    }

    private function path(string $id): string
    {
        return $this->directory . '/' . $id . '.sess';
    }

    /** Защита от path-traversal: только hex-id нужного формата. */
    private function valid(string $id): bool
    {
        return preg_match('/^[a-f0-9]{32,96}$/', $id) === 1;
    }
}
