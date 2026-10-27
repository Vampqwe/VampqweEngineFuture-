<?php
declare(strict_types=1);

namespace App\Core\Module\Database\Providers;

use App\Core\Module\Config\Contracts\ConfigRepositoryInterface;
use App\Core\Module\Database\Contracts\DatabaseInterface;
use App\Core\Module\Database\DatabaseManager;
use App\Core\System\Container\Contracts\ContainerInterface;
use App\Core\System\Contracts\ModuleProviderInterface;

/**
 * Провайдер модуля Database.
 *
 * Зависимости: Config (database.driver), Logger.
 * Отдаёт: DatabaseInterface. Уровень загрузки: после Config/Logger.
 */
class DatabaseServiceProvider implements ModuleProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        $container->bind(DatabaseInterface::class, static function (ContainerInterface $c): DatabaseManager {
            $driver = 'sqlite';
            if ($c->has(ConfigRepositoryInterface::class)) {
                $driver = (string) $c->get(ConfigRepositoryInterface::class)->get('database.driver', 'sqlite');
            }
            return new DatabaseManager($driver);
        });
    }

    public function boot(): void
    {
    }
}
