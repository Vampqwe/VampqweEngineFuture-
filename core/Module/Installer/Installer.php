<?php
declare(strict_types=1);

namespace App\Core\Module\Installer;

use App\Core\Module\Installer\Contracts\InstallerInterface;
use RuntimeException;

/**
 * Фасад модуля Installer.
 *
 * Зависимости: FileSystem (флаги/файлы), Database (миграции), Security (генерация app.key),
 *              Config, Doctor (проверка требований перед установкой).
 * РЕАЛИЗАЦИЯ В ПЛАНЕ.
 */
final class Installer implements InstallerInterface
{
    public function __construct(private readonly string $storagePath = '')
    {
    }

    public function requirements(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'php_ok'      => PHP_VERSION_ID >= 80100,
            'extensions'  => ['pdo', 'mbstring', 'json'],
        ];
    }

    public function install(array $options = []): bool
    {
        throw new RuntimeException('Модуль [Installer] объявлен в ядре, но ещё не реализован.');
    }

    public function isInstalling(): bool
    {
        return $this->storagePath !== '' && is_file($this->storagePath . '/.installing');
    }
}
