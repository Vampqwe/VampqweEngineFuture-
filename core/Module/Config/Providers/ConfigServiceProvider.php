<?php
declare(strict_types=1);

namespace App\Core\Module\Config\Providers;

use App\Core\System\Container\Contracts\ContainerInterface;
use App\Core\System\Contracts\ModuleProviderInterface;
use App\Core\Module\Config\ConfigRepository;
use App\Core\Module\Config\Contracts\ConfigRepositoryInterface;
use App\Core\Module\Config\Internal\Parsers\PhpArrayParser;

class ConfigServiceProvider implements ModuleProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        $container->instance(PhpArrayParser::class, new PhpArrayParser());

        $container->instance(ConfigRepository::class, new ConfigRepository(
            $container->get(PhpArrayParser::class),
            $container->get('config_path')
        ));

        $container->instance(
            ConfigRepositoryInterface::class, 
            $container->get(ConfigRepository::class)
        );
    }

    public function boot(): void
    {
    }
}