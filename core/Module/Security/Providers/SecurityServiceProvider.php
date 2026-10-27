<?php
declare(strict_types=1);

namespace App\Core\Module\Security\Providers;

use App\Core\Module\Security\Contracts\SecurityInterface;
use App\Core\Module\Security\Security;
use App\Core\System\Container\Contracts\ContainerInterface;
use App\Core\System\Contracts\ModuleProviderInterface;

/**
 * Провайдер модуля Security.
 *
 * Зависимости: Config (app.key), TimeDate.
 * Отдаёт: SecurityInterface.
 */
class SecurityServiceProvider implements ModuleProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        $container->bind(SecurityInterface::class, static function (ContainerInterface $c): Security {
            return new Security($c->get(\App\Core\Module\Config\Contracts\ConfigRepositoryInterface::class));
        });
    }

    public function boot(): void
    {
    }
}
