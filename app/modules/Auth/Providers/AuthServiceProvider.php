<?php
declare(strict_types=1);

namespace App\modules\Auth\Providers;

use App\Core\Module\Database\Contracts\DatabaseInterface;
use App\Core\Module\Hook\Contracts\HookInterface;
use App\Core\Module\Session\Contracts\SessionInterface;
use App\Core\Module\Template\Contracts\TemplateInterface;
use App\Core\System\Container\Contracts\ContainerInterface;
use App\Core\System\Contracts\ModuleProviderInterface;
use App\modules\Auth\AuthController;
use App\modules\Auth\AuthRepository;
use App\modules\Auth\Contracts\AuthManagerInterface;
use App\modules\Auth\Contracts\AuthRepositoryInterface;

/**
 * Провайдер пользовательского модуля Auth (app/modules/Auth).
 *
 * Зависимости — только через контракты ядра (п.3 Конституции): Database
 * (таблица users), Session (серверные сессии + CSRF), Template (формы в теме),
 * Hook (события auth.login/auth.logout/auth.register_allowed, п.4).
 *
 * Все зависимости опциональны и резолвятся лениво: до установки движка
 * контроллер честно отдаёт 503 вместо падения («движок не падает»).
 *
 * Публичные границы (п.2): AuthRepositoryInterface и AuthManagerInterface.
 * Маршрутизация: Kernel подключает /login, /register, /logout при наличии
 * AuthManagerInterface — ядро не знает деталей реализации.
 */
class AuthServiceProvider implements ModuleProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        $container->bind(AuthRepositoryInterface::class, static function (ContainerInterface $c): ?AuthRepository {
            if (!$c->has(DatabaseInterface::class)) {
                return null;
            }

            return new AuthRepository($c->get(DatabaseInterface::class));
        });

        $container->bind(AuthManagerInterface::class, static function (ContainerInterface $c): AuthController {
            return new AuthController(
                $c->has(AuthRepositoryInterface::class) ? $c->get(AuthRepositoryInterface::class) : null,
                $c->has(SessionInterface::class) ? $c->get(SessionInterface::class) : null,
                $c->has(TemplateInterface::class) ? $c->get(TemplateInterface::class) : null,
                $c->has(HookInterface::class) ? $c->get(HookInterface::class) : null,
            );
        });
    }

    public function boot(): void
    {
    }
}
