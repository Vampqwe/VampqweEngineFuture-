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
        $factory = static function (ContainerInterface $c): DatabaseManager {
            // Ленивое создание: PDO-соединение открывается только при первом запросе,
            // поэтому движок стартует даже без доступной СУБД.
            return new DatabaseManager(
                $c->get(ContainerInterface::class),
                $c->get(ConfigRepositoryInterface::class),
            );
        };

        // Синглтон по обоим ключам: один и тот же объект с тем же PDO.
        // Без этого Migrator (резолвил по контракту) и bin/engine (по классу)
        // получали РАЗНЫЕ экземпляры → разные sqlite-соединения → гонка
        // «таблица создана в одном коннекте, tableExists не видит её в другом».
        $container->bind(DatabaseInterface::class, $factory);
        $container->bind(DatabaseManager::class, static fn (ContainerInterface $c) => $c->get(DatabaseInterface::class));
    }

    public function boot(): void
    {
    }
}
