<?php
declare(strict_types=1);

namespace App\Core\System\Http\Middleware;

use App\Core\Module\Error\Contracts\ErrorPageInterface;
use App\Core\System\Enums\AppState;
use App\Core\System\Http\MiddlewareInterface;
use App\Core\System\Http\Request;
use App\Core\System\Http\Response;

/**
 * Глобальный middleware пользовательской дороги: если состояние приложения —
 * Maintenance, любой запрос получает страницу 503 от модуля Error (п.2 Конституции:
 * зависимостей от путей/файлов здесь нет, решение о состоянии принимает Kernel).
 */
final class MaintenanceMode implements MiddlewareInterface
{
    public function __construct(
        private readonly ErrorPageInterface $errorPages,
        private readonly AppState $state
    ) {}

    public function process(Request $request, callable $next): Response
    {
        if ($this->state === AppState::Maintenance) {
            return new Response($this->errorPages->render(503), 503);
        }

        return $next($request);
    }
}
