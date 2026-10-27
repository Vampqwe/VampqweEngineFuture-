<?php
declare(strict_types=1);

namespace App\Core\Module\TimeDate\Providers;

use App\Core\Module\Config\Contracts\ConfigRepositoryInterface;
use App\Core\Module\TimeDate\Contracts\TimeDateInterface;
use App\Core\Module\TimeDate\TimeDate;
use App\Core\System\Container\Contracts\ContainerInterface;
use App\Core\System\Contracts\ModuleProviderInterface;

/**
 * Провайдер модуля TimeDate.
 *
 * Зависимости: Config (уровень загрузки 4 → TimeDate идёт после него).
 * Отдаёт в контейнер: TimeDateInterface.
 */
class TimeDateServiceProvider implements ModuleProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        $container->bind(TimeDateInterface::class, static function (ContainerInterface $c): TimeDate {
            $tz = 'UTC';
            if ($c->has(ConfigRepositoryInterface::class)) {
                $tz = (string) $c->get(ConfigRepositoryInterface::class)->get('app.timezone', 'UTC');
            }
            return new TimeDate($tz);
        });
    }

    public function boot(): void
    {
    }
}
