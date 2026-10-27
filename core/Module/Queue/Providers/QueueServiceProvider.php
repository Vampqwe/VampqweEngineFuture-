<?php
declare(strict_types=1);

namespace App\Core\Module\Queue\Providers;

use App\Core\Module\Config\Contracts\ConfigRepositoryInterface;
use App\Core\Module\Queue\Contracts\QueueInterface;
use App\Core\Module\Queue\QueueManager;
use App\Core\System\Container\Contracts\ContainerInterface;
use App\Core\System\Contracts\ModuleProviderInterface;

/**
 * Провайдер модуля Queue.
 *
 * Зависимости: Config (queue.driver), FileSystem, Parser, TimeDate.
 * Отдаёт: QueueInterface. Уровень загрузки: поздний (после фундамента).
 */
class QueueServiceProvider implements ModuleProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        $container->bind(QueueInterface::class, static function (ContainerInterface $c): QueueManager {
            $driver = 'file';
            if ($c->has(ConfigRepositoryInterface::class)) {
                $driver = (string) $c->get(ConfigRepositoryInterface::class)->get('queue.driver', 'file');
            }
            return new QueueManager($driver);
        });
    }

    public function boot(): void
    {
    }
}
