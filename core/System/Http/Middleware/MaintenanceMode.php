<?php
declare(strict_types=1);

namespace App\Core\System\Http\Middleware;

use App\Core\System\Enums\AppState;
use App\Core\System\Http\MiddlewareInterface;
use App\Core\System\Http\Request;
use App\Core\System\Http\Response;
use App\Core\System\Support\ModuleBridge;

/**
 * Глобальный middleware пользовательской дороги: если состояние приложения —
 * Maintenance, любой запрос получает страницу 503 от модуля Error (через
 * ModuleBridge — правило R6b; зависимостей от путей/файлов здесь нет,
 * решение о состоянии принимает Kernel).
 */
final class MaintenanceMode implements MiddlewareInterface
{
    public function __construct(
        private readonly ModuleBridge $bridge,
        private readonly AppState $state
    ) {}

    public function process(Request $request, callable $next): Response
    {
        if ($this->state === AppState::Maintenance) {
            return new Response($this->bridge->errorPage(503), 503);
        }

        return $next($request);
    }
}
