<?php
declare(strict_types=1);

namespace App\Core\Module\Theme\Providers;

use App\Core\Module\Config\Contracts\ConfigRepositoryInterface;
use App\Core\Module\Database\Contracts\DatabaseInterface;
use App\Core\Module\FileSystem\Contracts\FileSystemInterface;
use App\Core\Module\Template\Contracts\TemplateInterface;
use App\Core\Module\Theme\Contracts\ThemeInterface;
use App\Core\Module\Theme\Internal\SiteRegistry;
use App\Core\Module\Theme\Theme;
use App\Core\System\Container\Contracts\ContainerInterface;
use App\Core\System\Contracts\ModuleProviderInterface;

/**
 * Провайдер модуля Theme.
 *
 * Зависимости (через контракты, п.3): Template (переключатель тем),
 * Database (реестр engine_sites — опционально, при недоступной БД
 * реестр пуст и работает эвристика поддомена), FileSystem (скан папок
 * view/app/), Config (template.theme — тема по умолчанию).
 * Пути — из контейнера ('app_view_path'; п.7, единая точка правды Paths).
 * Отдаёт: ThemeInterface.
 */
class ThemeServiceProvider implements ModuleProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        $container->bind(SiteRegistry::class, static function (ContainerInterface $c): SiteRegistry {
            // Database может быть отключён/недоступен — реестр деградирует в пустой.
            $db = $c->has(DatabaseInterface::class) ? $c->get(DatabaseInterface::class) : null;
            return new SiteRegistry($db);
        });

        $container->bind(ThemeInterface::class, static function (ContainerInterface $c): Theme {
            /** @var ConfigRepositoryInterface $config */
            $config = $c->get(ConfigRepositoryInterface::class);

            return new Theme(
                $c->get(TemplateInterface::class),
                $c->get(SiteRegistry::class),
                $c->get(FileSystemInterface::class),
                (string) $c->get('app_view_path'),
                (string) $config->get('template.theme', Theme::DEFAULT_THEME),
            );
        });
    }

    public function boot(): void
    {
    }
}
