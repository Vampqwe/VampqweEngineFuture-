<?php
declare(strict_types=1);

namespace App\Core\System\Http\Middleware;

use App\Core\System\Enums\AppState;
use App\Core\System\Http\MiddlewareInterface;
use App\Core\System\Http\Request;
use App\Core\System\Http\Response;

/**
 * Глобальный middleware: если включён режим обслуживания (storage/.maintenance),
 * любой запрос пользовательской дороги получает 503.
 */
final class MaintenanceMode implements MiddlewareInterface
{
    public function __construct(
        private readonly string $storagePath,
        private readonly AppState $state
    ) {}

    public function process(Request $request, callable $next): Response
    {
        if ($this->state === AppState::Maintenance) {
            return new Response('🔧 Сайт находится в режиме технического обслуживания.', 503);
        }

        return $next($request);
    }
}
