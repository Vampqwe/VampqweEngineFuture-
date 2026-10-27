<?php
declare(strict_types=1);

namespace App\Core\Module\Installer\Providers;

use App\Core\Module\Installer\Contracts\InstallerInterface;
use App\Core\Module\Installer\Installer;
use App\Core\System\Container\Contracts\ContainerInterface;
use App\Core\System\Contracts\ModuleProviderInterface;

/**
 * Провайдер модуля Installer.
 *
 * Зависимости: FileSystem, Database, Security, Config, Doctor.
 * Отдаёт: InstallerInterface. Уровень загрузки: самый поздний.
 */
class InstallerServiceProvider implements ModuleProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        $container->bind(InstallerInterface::class, static function (ContainerInterface $c): Installer {
            return new Installer((string) $c->get('storage_path'));
        });
    }

    public function boot(): void
    {
    }
}
