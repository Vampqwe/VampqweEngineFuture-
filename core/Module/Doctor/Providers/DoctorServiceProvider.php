<?php
declare(strict_types=1);

namespace App\Core\Module\Doctor\Providers;

use App\Core\Module\Doctor\Contracts\DoctorInterface;
use App\Core\Module\Doctor\Doctor;
use App\Core\System\Container\Contracts\ContainerInterface;
use App\Core\System\Contracts\ModuleProviderInterface;

/**
 * Провайдер модуля Doctor.
 *
 * Зависимости: Container, Config + контракты всех модулей (самодиагностика, п.9).
 * Отдаёт: DoctorInterface. Используется CLI (bin/engine doctor) и HTTP (/system/health).
 */
class DoctorServiceProvider implements ModuleProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        $container->bind(DoctorInterface::class, static fn(ContainerInterface $c): Doctor => new Doctor($c));
    }

    public function boot(): void
    {
    }
}
