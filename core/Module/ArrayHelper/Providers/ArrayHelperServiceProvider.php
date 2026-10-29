<?php
declare(strict_types=1);

namespace App\Core\Module\ArrayHelper\Providers;

use App\Core\Module\ArrayHelper\ArrayHelper;
use App\Core\Module\ArrayHelper\Contracts\ArrayHelperInterface;
use App\Core\System\Container\Contracts\ContainerInterface;
use App\Core\System\Contracts\ModuleProviderInterface;

class ArrayHelperServiceProvider implements ModuleProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        // Stateles-сервис, зависимостей нет — уровень 0 порядка загрузки (п.6).
        $container->bind(ArrayHelperInterface::class, static fn (): ArrayHelper => new ArrayHelper());
    }

    public function boot(): void
    {
    }
}
