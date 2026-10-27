<?php
declare(strict_types=1);

namespace App\Core\Module\Template\Providers;

use App\Core\Module\Config\Contracts\ConfigRepositoryInterface;
use App\Core\Module\Template\Contracts\TemplateInterface;
use App\Core\Module\Template\Template;
use App\Core\System\Container\Contracts\ContainerInterface;
use App\Core\System\Contracts\ModuleProviderInterface;

/**
 * Провайдер модуля Template.
 *
 * Зависимости: Config (template.cache/theme), пути — из контейнера
 * ('core_view_path', 'app_view_path', 'cache_path'; п.7, единая точка правды Paths).
 * Отдаёт: TemplateInterface.
 */
class TemplateServiceProvider implements ModuleProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        $container->bind(TemplateInterface::class, static function (ContainerInterface $c): Template {
            /** @var ConfigRepositoryInterface $config */
            $config = $c->get(ConfigRepositoryInterface::class);

            $template = new Template(
                (string) $c->get('core_view_path'),
                (string) $c->get('app_view_path'),
                rtrim((string) $c->get('cache_path'), '/') . '/templates',
                (bool) $config->get('template.cache', true),
            );

            $template->setTheme((string) $config->get('template.theme', Template::DEFAULT_THEME));
            $template->share('appName', (string) $config->get('app.name', 'VampqweEngineFuture'));

            return $template;
        });
    }

    public function boot(): void
    {
    }
}
