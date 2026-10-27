<?php
declare(strict_types=1);

namespace App\Core\Module\Error\Providers;

use App\Core\Module\Config\Contracts\ConfigRepositoryInterface;
use App\Core\Module\Error\Contracts\ErrorPageInterface;
use App\Core\Module\Error\ErrorPageRenderer;
use App\Core\System\Container\Contracts\ContainerInterface;
use App\Core\System\Contracts\ModuleProviderInterface;

/**
 * Провайдер модуля Error.
 *
 * Зависимости: Config (app.debug), Template (в плане).
 * Отдаёт: ErrorPageInterface.
 */
class ErrorServiceProvider implements ModuleProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        $container->bind(ErrorPageInterface::class, static function (ContainerInterface $c): ErrorPageRenderer {
            $debug = false;
            if ($c->has(ConfigRepositoryInterface::class)) {
                $debug = (bool) $c->get(ConfigRepositoryInterface::class)->get('app.debug', false);
            }
            return new ErrorPageRenderer($debug);
        });
    }

    public function boot(): void
    {
    }
}
