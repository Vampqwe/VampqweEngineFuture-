<?php
declare(strict_types=1);

namespace App\Core\Module\Queue\Providers;

use App\Core\Module\Config\Contracts\ConfigRepositoryInterface;
use App\Core\Module\Logger\Contracts\LoggerInterface;
use App\Core\Module\Queue\Contracts\QueueInterface;
use App\Core\Module\Queue\QueueManager;
use App\Core\System\Container\Contracts\ContainerInterface;
use App\Core\System\Contracts\ModuleProviderInterface;

/**
 * Провайдер модуля Queue.
 *
 * Зависимости: Config (queue.driver, queue.max_attempts, ...), Paths (queue_path).
 * Отдаёт: QueueInterface. Уровень загрузки: поздний (после фундамента).
 */
class QueueServiceProvider implements ModuleProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        $container->bind(QueueInterface::class, static function (ContainerInterface $c): QueueManager {
            /** @var ConfigRepositoryInterface $config */
            $config = $c->get(ConfigRepositoryInterface::class);

            $backends = (array) $config->get('queue.backends', []);
            // Путь file-бэкенда — из ЕДИНОЙ точки правды (Paths), не из конфига.
            if ($c->has('queue_path')) {
                $backends['file']['path'] = (string) $c->get('queue_path');
            }

            $logger = static function (string $level, string $msg) use ($c): void {
                if ($c->has(LoggerInterface::class)) {
                    /** @var LoggerInterface $log */
                    $log = $c->get(LoggerInterface::class);
                    $log->{$level}($msg, ['channel' => 'Queue']);
                }
            };

            return QueueManager::fromConfig(
                (string) $config->get('queue.driver', 'file'),
                $backends,
                (int) $config->get('queue.max_attempts', 3),
                (int) $config->get('queue.retry_after', 60),
                (int) $config->get('queue.visibility', 30),
                $logger,
            );
        });
    }

    public function boot(): void
    {
    }
}
