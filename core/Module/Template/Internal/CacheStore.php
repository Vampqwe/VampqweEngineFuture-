<?php
declare(strict_types=1);

namespace App\Core\Module\Template\Internal;

/**
 * Кэш скомпилированных шаблонов на диске (внутренняя кухня Template).
 *
 * Layout: <cachePath>/<md5(абсолютный путь к .phtml)>_<mtime>.php —
 * изменение исходника автоматически меняет ключ, инвалидация «бессрочная».
 */
final class CacheStore
{
    public function __construct(
        private readonly string $cachePath,
        private readonly bool $enabled,
    ) {
    }

    /** Скомпилированный PHP для файла шаблона (или null — нет валидного кэша). */
    public function get(string $sourceFile): ?string
    {
        if (!$this->enabled || !is_file($sourceFile)) {
            return null;
        }

        $compiled = $this->compiledPath($sourceFile);
        if (is_file($compiled)) {
            $php = file_get_contents($compiled);
            if ($php !== false) {
                return $php;
            }
        }

        return null;
    }

    public function put(string $sourceFile, string $compiledPhp): void
    {
        if (!$this->enabled) {
            return;
        }

        $target = $this->compiledPath($sourceFile);
        $dir    = dirname($target);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        // атомарная запись: во временный файл + rename
        $tmp = $target . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $compiledPhp, LOCK_EX) !== false) {
            @rename($tmp, $target);
        } else {
            @unlink($tmp);
        }
    }

    /** Полная очистка кэша (использует CLI template:clear и Doctor). */
    public function clearAll(): int
    {
        $count = 0;
        if (!is_dir($this->cachePath)) {
            return 0;
        }

        foreach (glob($this->cachePath . '/*.php') ?: [] as $file) {
            if (@unlink($file)) {
                $count++;
            }
        }

        return $count;
    }

    public function cachePath(): string
    {
        return $this->cachePath;
    }

    private function compiledPath(string $sourceFile): string
    {
        return $this->cachePath . '/' . md5($sourceFile) . '_' . (int) @filemtime($sourceFile) . '.php';
    }
}
