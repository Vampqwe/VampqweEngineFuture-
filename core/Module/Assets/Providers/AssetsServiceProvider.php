<?php
declare(strict_types=1);

namespace App\Core\Module\Assets\Providers;

use App\Core\Module\Assets\Contracts\AssetsInterface;
use App\Core\Module\Assets\Internal\AssetsRegistry;
use App\Core\Module\Config\Contracts\ConfigRepositoryInterface;
use App\Core\Module\FileSystem\Contracts\FileSystemInterface;
use App\Core\System\Container\Contracts\ContainerInterface;
use App\Core\System\Contracts\ModuleProviderInterface;

/**
 * Провайдер модуля Assets.
 *
 * Зависимости (через контракты, п.3): FileSystem — все файловые операции.
 * Пути — из контейнера ('core_view_path', 'app_view_path', 'storage_path';
 * п.7 Конституции: единая точка правды Paths::MAP, ключей assets нет —
 * это подпути существующих ключей). Config — опционально (зарегистрировать
 * дефолтные стили темы можно будет через хук, не здесь).
 * Отдаёт: AssetsInterface (синглтон на запрос).
 */
class AssetsServiceProvider implements ModuleProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        $container->bind(AssetsInterface::class, static function (ContainerInterface $c): AssetsInterface {
            /** @var FileSystemInterface $fs */
            $fs = $c->get(FileSystemInterface::class);

            return new AssetsRegistry(
                $fs,
                (string) $c->get('core_view_path') . '/assets',
                (string) $c->get('app_view_path'),
                (string) $c->get('storage_path') . '/sites',
                $c->has(\App\Core\Module\Hook\Contracts\HookInterface::class)
                    ? $c->get(\App\Core\Module\Hook\Contracts\HookInterface::class)
                    : null,
            );
        });
    }

    public function boot(): void
    {
    }
}
