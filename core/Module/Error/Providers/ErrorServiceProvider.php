<?php
declare(strict_types=1);

namespace App\Core\Module\Error\Providers;

use App\Core\Module\Config\Contracts\ConfigRepositoryInterface;
use App\Core\Module\Error\Contracts\ErrorPageInterface;
use App\Core\Module\Error\ErrorPageRenderer;
use App\Core\Module\Template\Contracts\TemplateInterface;
use App\Core\System\Container\Contracts\ContainerInterface;
use App\Core\System\Contracts\ModuleProviderInterface;

/**
 * Провайдер модуля Error.
 *
 * Зависимости: Config (app.debug), Template (опционально — через has()).
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

            // Template может ещё не существовать (ранний бутстрап) — тогда fallback-страницы.
            $views = $c->has(TemplateInterface::class) ? $c->get(TemplateInterface::class) : null;

            return new ErrorPageRenderer($debug, $views instanceof TemplateInterface ? $views : null);
        });
    }

    public function boot(): void
    {
    }
}
