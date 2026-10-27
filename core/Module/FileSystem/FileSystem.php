<?php
declare(strict_types=1);

namespace App\Core\Module\FileSystem;

use App\Core\Module\FileSystem\Contracts\FileSystemInterface;
use RuntimeException;

class FileSystem implements FileSystemInterface
{
    public function exists(string $path): bool
    {
        return file_exists($path);
    }

    public function isFile(string $path): bool
    {
        return is_file($path);
    }

    public function isDirectory(string $path): bool
    {
        return is_dir($path);
    }

    public function isWritable(string $path): bool
    {
        return is_writable($path);
    }

    public function isReadable(string $path): bool
    {
        return is_readable($path);
    }

    public function createDirectory(string $path, int $permissions = 0755): bool
    {
        if ($this->exists($path)) {
            return true;
        }

        return mkdir($path, $permissions, true);
    }

    public function createFile(string $path, string $content = ''): bool
    {
        $directory = dirname($path);
        
        if (!$this->exists($directory)) {
            $this->createDirectory($directory);
        }

        return file_put_contents($path, $content) !== false;
    }

    public function delete(string $path): bool
    {
        if (!$this->exists($path)) {
            return false;
        }

        return unlink($path);
    }

    public function deleteDirectory(string $path): bool
    {
        if (!$this->isDirectory($path)) {
            return false;
        }

        $items = scandir($path);
        
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path . '/' . $item;
            
            if ($this->isDirectory($itemPath)) {
                $this->deleteDirectory($itemPath);
            } else {
                $this->delete($itemPath);
            }
        }

        return rmdir($path);
    }

    public function read(string $path): string
    {
        if (!$this->isFile($path)) {
            throw new RuntimeException("Файл не существует: {$path}");
        }

        $content = file_get_contents($path);
        
        if ($content === false) {
            throw new RuntimeException("Не удалось прочитать файл: {$path}");
        }

        return $content;
    }

    public function write(string $path, string $content): bool
    {
        return file_put_contents($path, $content) !== false;
    }

    public function append(string $path, string $content): bool
    {
        return file_put_contents($path, $content, FILE_APPEND) !== false;
    }

    public function copy(string $source, string $destination): bool
    {
        if (!$this->isFile($source)) {
            throw new RuntimeException("Исходный файл не существует: {$source}");
        }

        return copy($source, $destination);
    }

    public function move(string $source, string $destination): bool
    {
        if (!$this->exists($source)) {
            throw new RuntimeException("Файл не существует: {$source}");
        }

        return rename($source, $destination);
    }

    public function lastModified(string $path): int
    {
        if (!$this->exists($path)) {
            throw new RuntimeException("Файл не существует: {$path}");
        }

        return filemtime($path);
    }

    public function size(string $path): int
    {
        if (!$this->isFile($path)) {
            throw new RuntimeException("Файл не существует: {$path}");
        }

        return filesize($path);
    }

    public function extension(string $path): string
    {
        return pathinfo($path, PATHINFO_EXTENSION);
    }

    public function basename(string $path): string
    {
        return pathinfo($path, PATHINFO_BASENAME);
    }

    public function dirname(string $path): string
    {
        return pathinfo($path, PATHINFO_DIRNAME);
    }
}