<?php
declare(strict_types=1);

namespace App\Core\Module\Cache\Providers;

use App\Core\Module\Cache\CacheManager;
use App\Core\Module\Cache\Contracts\CacheInterface;
use App\Core\Module\Config\Contracts\ConfigRepositoryInterface;
use App\Core\Module\Logger\Contracts\LoggerInterface;
use App\Core\System\Container\Contracts\ContainerInterface;
use App\Core\System\Contracts\ModuleProviderInterface;

/**
 * Провайдер модуля Cache.
 *
 * Зависимости: Config (cache.driver, cache.prefix, cache.stores),
 *   Paths (cache_path — единая точка правды о путях, п.7).
 * Отдаёт: CacheInterface. Уровень загрузки: после Config/FileSystem/TimeDate.
 */
class CacheServiceProvider implements ModuleProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        $container->bind(CacheInterface::class, static function (ContainerInterface $c): CacheManager {
            /** @var ConfigRepositoryInterface $config */
            $config = $c->get(ConfigRepositoryInterface::class);

            $stores = (array) $config->get('cache.stores', []);
            // Путь file-стора резолвим из ЕДИНОЙ точки правды (Paths), а не из конфига.
            if ($c->has('cache_path')) {
                $stores['file']['path'] = (string) $c->get('cache_path');
            }

            $logger = static function (string $level, string $msg) use ($c): void {
                if ($c->has(LoggerInterface::class)) {
                    /** @var LoggerInterface $log */
                    $log = $c->get(LoggerInterface::class);
                    $log->{$level}($msg, ['channel' => 'Cache']);
                }
            };

            return CacheManager::fromConfig(
                (string) $config->get('cache.driver', 'file'),
                (string) $config->get('cache.prefix', 'engine'),
                $stores,
                $logger,
            );
        });
    }

    public function boot(): void
    {
    }
}
