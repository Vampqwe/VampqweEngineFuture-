<?php
declare(strict_types=1);

namespace App\Core\Module\Installer\Providers;

use App\Core\Module\Config\Contracts\ConfigRepositoryInterface;
use App\Core\Module\FileSystem\Contracts\FileSystemInterface;
use App\Core\Module\Installer\Contracts\InstallerInterface;
use App\Core\Module\Installer\Contracts\InstallerPageInterface;
use App\Core\Module\Installer\Installer;
use App\Core\Module\Logger\Contracts\LoggerInterface;
use App\Core\Module\Security\Contracts\SecurityInterface;
use App\Core\System\Container\Contracts\ContainerInterface;
use App\Core\System\Contracts\ModuleProviderInterface;

/**
 * Провайдер модуля Installer.
 *
 * Зависимости (резолвятся лениво при первом обращении, п.3):
 * FileSystem, Security, Config, Logger + пути base_path/storage_path из контейнера (п.7).
 * Отдаёт: InstallerInterface и InstallerPageInterface (один и тот же синглтон).
 */
class InstallerServiceProvider implements ModuleProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        $factory = static function (ContainerInterface $c): Installer {
            return new Installer(
                (string) $c->get('base_path'),
                (string) $c->get('storage_path'),
                $c->get(FileSystemInterface::class),
                $c->get(SecurityInterface::class),
                $c->get(ConfigRepositoryInterface::class),
                $c->get(LoggerInterface::class),
            );
        };

        // Один экземпляр на оба контракта — не дублируем состояние
        $container->bind(InstallerInterface::class, $factory);
        $container->bind(InstallerPageInterface::class, static function (ContainerInterface $c) use ($factory): Installer {
            /** @var Installer $installer */
            $installer = $c->get(InstallerInterface::class);
            return $installer;
        });
    }

    public function boot(): void
    {
    }
}
