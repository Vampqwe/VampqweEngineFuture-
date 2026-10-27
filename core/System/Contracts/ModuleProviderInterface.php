<?php
declare(strict_types=1);

namespace App\Core\System\Contracts;

use App\Core\System\Container\Contracts\ContainerInterface;

interface ModuleProviderInterface
{
    public function register(ContainerInterface $container): void;
    public function boot(): void;
}