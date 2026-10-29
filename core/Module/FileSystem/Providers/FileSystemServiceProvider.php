<?php
declare(strict_types=1);

namespace App\Core\Module\FileSystem\Providers;

use App\Core\System\Container\Contracts\ContainerInterface;
use App\Core\System\Contracts\ModuleProviderInterface;
use App\Core\Module\FileSystem\Contracts\FileSystemInterface;
use App\Core\Module\FileSystem\FileSystem;

class FileSystemServiceProvider implements ModuleProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        $container->instance(FileSystemInterface::class, new FileSystem());
    }

    public function boot(): void
    {
    }
}