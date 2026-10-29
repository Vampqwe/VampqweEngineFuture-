<?php
declare(strict_types=1);

namespace App\Core\Module\Session\Providers;

use App\Core\Module\Config\Contracts\ConfigRepositoryInterface;
use App\Core\Module\Security\Contracts\SecurityInterface;
use App\Core\Module\Session\Contracts\SessionInterface;
use App\Core\Module\Session\Contracts\SessionStoreMigratorInterface;
use App\Core\Module\Session\Internal\Stores\FileStore;
use App\Core\Module\Session\Internal\Stores\RedisStore;
use App\Core\Module\Session\ModuleSession;
use App\Core\System\Container\Contracts\ContainerInterface;
use App\Core\System\Contracts\ModuleProviderInterface;

/**
 * Провайдер модуля Session.
 *
 * Зависимости: Config (session.*), Security (случайные токены), путь sessions_path
 * (единая точка правды Paths, п.7 Конституции).
 * Отдаёт: SessionInterface. Уровень загрузки: после Config/Security (уровень 4+).
 */
class SessionServiceProvider implements ModuleProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        $container->bind(SessionInterface::class, static function (ContainerInterface $c): ModuleSession {
            $config   = $c->get(ConfigRepositoryInterface::class);
            $security = $c->get(SecurityInterface::class);

            $session = new ModuleSession($config, $security);

            // Пути — ТОЛЬКО из контейнера (никаких dirname/BASE_PATH здесь).
            $sessionsPath = (string) $c->get('sessions_path');
            $redisCfg     = (array) $config->get('session.redis', []);

            $session->setStores([
                'file'  => new FileStore($sessionsPath),
                'redis' => new RedisStore(
                    host:     (string) ($redisCfg['host'] ?? '127.0.0.1'),
                    port:     (int) ($redisCfg['port'] ?? 6379),
                    password: (string) ($redisCfg['password'] ?? ''),
                    database: (int) ($redisCfg['database'] ?? 0),
                ),
            ]);

            return $session;
        });

        // Capability-контракт фоновой миграции сторов: тот же синглтон,
        // отдельный ключ — ядро резолвит способность, не зная сторов (п.4).
        $container->bind(SessionStoreMigratorInterface::class, static function (ContainerInterface $c): SessionStoreMigratorInterface {
            /** @var SessionStoreMigratorInterface $m */
            $m = $c->get(SessionInterface::class);
            return $m;
        });
    }

    public function boot(): void
    {
    }
}
