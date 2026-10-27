<?php
declare(strict_types=1);

namespace App\Core\Module\Template\Providers;

use App\Core\Module\Template\Contracts\TemplateInterface;
use App\Core\Module\Template\Template;
use App\Core\System\Container\Contracts\ContainerInterface;
use App\Core\System\Contracts\ModuleProviderInterface;

/**
 * Провайдер модуля Template.
 *
 * Зависимости: FileSystem, Config, Parser; путь к views — из контейнера ('view_path', п.7).
 * Отдаёт: TemplateInterface.
 */
class TemplateServiceProvider implements ModuleProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        $container->bind(TemplateInterface::class, static function (ContainerInterface $c): Template {
            return new Template((string) $c->get('view_path'));
        });
    }

    public function boot(): void
    {
    }
}
