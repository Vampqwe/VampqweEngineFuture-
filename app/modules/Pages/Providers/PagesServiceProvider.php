<?php
declare(strict_types=1);

namespace App\modules\Pages\Providers;

use App\Core\Module\Database\Contracts\DatabaseInterface;
use App\Core\Module\Error\Contracts\ErrorPageInterface;
use App\Core\Module\Template\Contracts\TemplateInterface;
use App\Core\System\Container\Contracts\ContainerInterface;
use App\Core\System\Contracts\ModuleProviderInterface;
use App\modules\Pages\Contracts\PageRendererInterface;
use App\modules\Pages\Contracts\PageRepositoryInterface;
use App\modules\Pages\PageController;
use App\modules\Pages\PageRepository;

/**
 * Провайдер пользовательского модуля Pages (app/modules/Pages).
 *
 * Зависимости (только через контракты, п.3 Конституции): Database
 * (таблица pages), Template (рендер страниц в теме сайта), Error
 * (страница 404). Все зависимости резолвятся лениво и опционально:
 * до установки движка Database может быть недоступен — тогда контроллер
 * честно отдаёт 404, а не валит запрос (принцип «движок не падает»).
 *
 * Отдаёт: PageRepositoryInterface (единственная публичная граница, п.2).
 * Маршрутизация: Kernel подключает PageController::show() как обработчик
 * маршрута /{slug} пользовательской дороги при наличии этого контракта.
 */
class PagesServiceProvider implements ModuleProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        // Репозиторий живёт только если есть Database — иначе модуль деградирует.
        $container->bind(PageRepositoryInterface::class, static function (ContainerInterface $c): ?PageRepository {
            if (!$c->has(DatabaseInterface::class)) {
                return null;
            }

            return new PageRepository($c->get(DatabaseInterface::class));
        });

        // Публичная HTTP-граница модуля — контракт PageRendererInterface:
        // ядро подключает /{slug} только по нему (п.1 Конституции).
        $container->bind(PageRendererInterface::class, static function (ContainerInterface $c): PageRendererInterface {
            return new PageController(
                $c->has(PageRepositoryInterface::class) ? $c->get(PageRepositoryInterface::class) : null,
                $c->has(TemplateInterface::class) ? $c->get(TemplateInterface::class) : null,
                $c->has(DatabaseInterface::class) ? $c->get(DatabaseInterface::class) : null,
                $c->has(ErrorPageInterface::class) ? $c->get(ErrorPageInterface::class) : null,
            );
        });
    }

    public function boot(): void
    {
    }
}
