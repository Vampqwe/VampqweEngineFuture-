<?php
declare(strict_types=1);

namespace App\modules\Admin\Providers;

use App\Core\Module\Database\Contracts\DatabaseInterface;
use App\Core\Module\Doctor\Contracts\DoctorInterface;
use App\Core\Module\Hook\Contracts\HookInterface;
use App\Core\Module\Security\Contracts\SecurityInterface;
use App\Core\Module\Session\Contracts\SessionInterface;
use App\Core\Module\Template\Contracts\TemplateInterface;
use App\Core\System\Container\Contracts\ContainerInterface;
use App\Core\System\Contracts\ModuleProviderInterface;
use App\modules\Admin\Contracts\AdminPanelInterface;
use App\modules\Admin\Contracts\GuardInterface;
use App\modules\Admin\Internal\AdminController;
use App\modules\Admin\Internal\AdminGuard;
use App\modules\Admin\Internal\DashboardSection;
use App\modules\Admin\Internal\PagesSection;
use App\modules\Admin\Internal\SystemSection;
use App\modules\Admin\Internal\UserRepository;
use App\modules\Admin\Internal\UsersSection;
use App\modules\Auth\Contracts\AuthRepositoryInterface;
use App\modules\Pages\Contracts\PageRepositoryInterface;

/**
 * Провайдер пользовательского модуля Admin (app/modules/Admin).
 *
 * Порядок загрузки (п.6): после Auth и Pages — панель использует их публичные
 * контракты. Все зависимости опциональны и резолвятся лениво: до установки
 * движка панель деградирует честно (503/редирект), а не падает.
 *
 * Публичные границы (п.2): AdminPanelInterface, GuardInterface,
 * AdminSectionInterface. Всё остальное — Internal/, наружу не отдаётся.
 */
class AdminServiceProvider implements ModuleProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        // Страж доступа: сессии + роли из users (контракт Auth) + SuperAdmin ядра.
        $container->bind(GuardInterface::class, static function (ContainerInterface $c): AdminGuard {
            return new AdminGuard(
                $c->has(SessionInterface::class) ? $c->get(SessionInterface::class) : null,
                $c->has(SecurityInterface::class) ? $c->get(SecurityInterface::class) : null,
                $c->has(AuthRepositoryInterface::class) ? $c->get(AuthRepositoryInterface::class) : null,
            );
        });

        // Внутреннее хранилище пользователей панели (таблица users через БД ядра).
        $container->bind(UserRepository::class, static function (ContainerInterface $c): ?UserRepository {
            if (!$c->has(DatabaseInterface::class)) {
                return null;
            }

            return new UserRepository($c->get(DatabaseInterface::class));
        });

        // Разделы панели — каждый лениво, только со своими контрактами.
        $container->bind(DashboardSection::class, static function (ContainerInterface $c): DashboardSection {
            return new DashboardSection(
                $c->has(TemplateInterface::class) ? $c->get(TemplateInterface::class) : null,
                $c->has(DatabaseInterface::class) ? $c->get(DatabaseInterface::class) : null,
            );
        });

        $container->bind(PagesSection::class, static function (ContainerInterface $c): PagesSection {
            return new PagesSection(
                $c->has(PageRepositoryInterface::class) ? $c->get(PageRepositoryInterface::class) : null,
                $c->has(TemplateInterface::class) ? $c->get(TemplateInterface::class) : null,
            );
        });

        $container->bind(UsersSection::class, static function (ContainerInterface $c): UsersSection {
            return new UsersSection(
                $c->has(UserRepository::class) ? $c->get(UserRepository::class) : null,
                $c->has(TemplateInterface::class) ? $c->get(TemplateInterface::class) : null,
            );
        });

        $container->bind(SystemSection::class, static function (ContainerInterface $c): SystemSection {
            return new SystemSection(
                $c->has(DoctorInterface::class) ? $c->get(DoctorInterface::class) : null,
                $c->has(TemplateInterface::class) ? $c->get(TemplateInterface::class) : null,
            );
        });

        // Фасад всей дороги /admin/*.
        $container->bind(AdminPanelInterface::class, static function (ContainerInterface $c): AdminController {
            $controller = new AdminController(
                $c,
                $c->has(GuardInterface::class) ? $c->get(GuardInterface::class) : null,
                $c->has(HookInterface::class) ? $c->get(HookInterface::class) : null,
            );

            // Разделы самой панели объявляются через публичный API фасада
            // (registerSections), а не хардкодом внутри контроллера;
            // сторонние модули объявляют свои разделы тем же способом
            // через хук 'admin.sections' (п.4 Конституции).
            $controller->registerSections([
                DashboardSection::class,
                PagesSection::class,
                UsersSection::class,
                SystemSection::class,
            ]);

            return $controller;
        });
    }

    public function boot(): void
    {
    }
}
