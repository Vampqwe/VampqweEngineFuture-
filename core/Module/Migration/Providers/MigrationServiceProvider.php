<?php
declare(strict_types=1);

namespace App\Core\Module\Migration\Providers;

use App\Core\Module\Database\Contracts\DatabaseInterface;
use App\Core\Module\FileSystem\Contracts\FileSystemInterface;
use App\Core\Module\Hook\Contracts\HookInterface;
use App\Core\Module\Logger\Contracts\LoggerInterface;
use App\Core\Module\Migration\Contracts\MigrationInterface;
use App\Core\Module\Migration\Internal\SqlSchemaMigrator;
use App\Core\Module\TimeDate\Contracts\TimeDateInterface;
use App\Core\System\Container\Contracts\ContainerInterface;
use App\Core\System\Contracts\ModuleProviderInterface;
use RuntimeException;

/**
 * Провайдер модуля Migration.
 *
 * Зависимости: Database, FileSystem, TimeDate, Logger, Hook + 'database_path'
 * (единая точка правды Paths — п.7). Уровень загрузки: после Database.
 * Отдаёт: MigrationInterface (единственная публичная граница модуля).
 *
 * Регистрация ленивая (bind): реестр engine_migrations и каталог дампов не
 * трогается, пока кто-нибудь реально не обратится к миграции — движок
 * стартует и без доступной СУБД (как и Database).
 */
class MigrationServiceProvider implements ModuleProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        $container->bind(MigrationInterface::class, static function (ContainerInterface $c): SqlSchemaMigrator {
            if (!$c->has('database_path')) {
                throw new RuntimeException(
                    'Модуль [Migration]: путь database_path не зарегистрирован — проверьте Support\Paths.'
                );
            }

            return new SqlSchemaMigrator(
                $c->get(DatabaseInterface::class),
                $c->get(FileSystemInterface::class),
                $c->get(TimeDateInterface::class),
                $c->get(LoggerInterface::class),
                $c->get(HookInterface::class),
                (string) $c->get('database_path'),
            );
        });
    }

    public function boot(): void
    {
    }
}
