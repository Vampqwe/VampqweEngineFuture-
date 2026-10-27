<?php
declare(strict_types=1);

namespace App\Core\Module\Cache\Providers;

use App\Core\Module\Cache\CacheManager;
use App\Core\Module\Cache\Contracts\CacheInterface;
use App\Core\Module\Config\Contracts\ConfigRepositoryInterface;
use App\Core\System\Container\Contracts\ContainerInterface;
use App\Core\System\Contracts\ModuleProviderInterface;

/**
 * Провайдер модуля Cache.
 *
 * Зависимости: Config (cache.driver, cache.ttl), FileSystem (file-стор), TimeDate (TTL).
 * Отдаёт: CacheInterface. Уровень загрузки: после Config/FileSystem/TimeDate.
 */
class CacheServiceProvider implements ModuleProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        $container->bind(CacheInterface::class, static function (ContainerInterface $c): CacheManager {
            $driver = 'file';
            if ($c->has(ConfigRepositoryInterface::class)) {
                $driver = (string) $c->get(ConfigRepositoryInterface::class)->get('cache.driver', 'file');
            }
            return new CacheManager($driver);
        });
    }

    public function boot(): void
    {
    }
}
