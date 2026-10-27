<?php
declare(strict_types=1);

namespace App\Core\Module\Logger\Providers;

use App\Core\System\Container\Contracts\ContainerInterface;
use App\Core\System\Contracts\ModuleProviderInterface;
use App\Core\Module\Logger\Contracts\LoggerInterface;
use App\Core\Module\Logger\Logger;
use App\Core\Module\FileSystem\Contracts\FileSystemInterface;

class LoggerServiceProvider implements ModuleProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        $container->instance(LoggerInterface::class, new Logger(
            $container->get(FileSystemInterface::class),
            $container->get('storage_path') . '/logs',
            'debug'
        ));
    }

    public function boot(): void
    {
    }
}