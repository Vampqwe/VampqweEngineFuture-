<?php
declare(strict_types=1);

namespace App\Core\Module\Logger\Providers;

use App\Core\System\Container\Contracts\ContainerInterface;
use App\Core\System\Contracts\ModuleProviderInterface;
use App\Core\Module\Config\Contracts\ConfigRepositoryInterface;
use App\Core\Module\Logger\Contracts\LoggerInterface;
use App\Core\Module\Logger\Logger;
use App\Core\Module\FileSystem\Contracts\FileSystemInterface;

class LoggerServiceProvider implements ModuleProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        // Ленивая регистрация: уровень логирования берётся из конфигурации (env LOG_LEVEL),
        // а не хардкодится. Зависимости резолвятся при первом обращении к Logger.
        $container->bind(LoggerInterface::class, function (ContainerInterface $c): Logger {
            $minLevel = 'debug';
            if ($c->has(ConfigRepositoryInterface::class)) {
                $minLevel = strtolower((string) $c->get(ConfigRepositoryInterface::class)->get('app.log_level', 'debug'));
            }

            return new Logger(
                $c->get(FileSystemInterface::class),
                $c->get('logs_path'),
                $minLevel
            );
        });
    }

    public function boot(): void
    {
    }
}