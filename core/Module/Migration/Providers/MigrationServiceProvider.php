<?php
declare(strict_types=1);

namespace App\Core\Module\Migration\Providers;

use App\Core\Module\Database\Contracts\DatabaseInterface;
use App\Core\Module\Hook\Contracts\HookInterface;
use App\Core\Module\Logger\Contracts\LoggerInterface;
use App\Core\Module\Migration\Contracts\MigratorInterface;
use App\Core\Module\Migration\Internal\Migrator;
use App\Core\Module\Migration\Migration;
use App\Core\Module\TimeDate\Contracts\TimeDateInterface;
use App\Core\System\Container\Contracts\ContainerInterface;
use App\Core\System\Contracts\ModuleProviderInterface;
use RuntimeException;

/**
 * Провайдер модуля Migration.
 *
 * Зависимости: Database, TimeDate, Logger, Hook + 'migrations_path'
 * (единая точка правды Paths — п.7). Уровень загрузки: после Database.
 * Отдаёт: MigratorInterface (+ фасад Migration).
 *
 * Регистрация ленивая (bind): реестр engine_migrations не трогается,
 * пока кто-нибудь реально не обратится к миграциям — движок стартует
 * и без доступной СУБД (как и Database).
 */
class MigrationServiceProvider implements ModuleProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        $container->bind(MigratorInterface::class, static function (ContainerInterface $c): Migrator {
            if (!$c->has('migrations_path')) {
                throw new RuntimeException(
                    'Модуль [Migration]: путь migrations_path не зарегистрирован — проверьте Support\Paths.'
                );
            }

            return new Migrator(
                $c->get(DatabaseInterface::class),
                $c->get(TimeDateInterface::class),
                $c->get(LoggerInterface::class),
                $c->get(HookInterface::class),
                (string) $c->get('migrations_path'),
            );
        });

        $container->bind(Migration::class, static fn (ContainerInterface $c): Migration =>
            new Migration($c->get(MigratorInterface::class)));
    }

    public function boot(): void
    {
    }
}
