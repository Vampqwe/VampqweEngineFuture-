<?php
declare(strict_types=1);

namespace App\Core\Module\Parser\Providers;

use App\Core\Module\Parser\Contracts\ParserInterface;
use App\Core\Module\Parser\Parser;
use App\Core\System\Container\Contracts\ContainerInterface;
use App\Core\System\Contracts\ModuleProviderInterface;

/**
 * Провайдер модуля Parser.
 *
 * Зависимости: НЕТ (уровень 0).
 * Отдаёт в контейнер: ParserInterface.
 */
class ParserServiceProvider implements ModuleProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        $container->bind(ParserInterface::class, static fn(): Parser => new Parser());
    }

    public function boot(): void
    {
    }
}
