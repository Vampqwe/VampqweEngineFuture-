<?php
declare(strict_types=1);

namespace App\Core\Module\FileSystem\Contracts;

interface FileSystemInterface
{
    public function exists(string $path): bool;
    public function isFile(string $path): bool;
    public function isDirectory(string $path): bool;
    public function isWritable(string $path): bool;
    public function isReadable(string $path): bool;
    
    public function createDirectory(string $path, int $permissions = 0755): bool;
    public function createFile(string $path, string $content = ''): bool;
    public function delete(string $path): bool;
    public function deleteDirectory(string $path): bool;
    
    public function read(string $path): string;
    public function write(string $path, string $content): bool;
    public function append(string $path, string $content): bool;
    
    public function copy(string $source, string $destination): bool;
    public function move(string $source, string $destination): bool;
    
    public function lastModified(string $path): int;
    public function size(string $path): int;
    public function extension(string $path): string;
    public function basename(string $path): string;
    public function dirname(string $path): string;
}