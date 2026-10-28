<?php
declare(strict_types=1);

namespace App\Core\Module\Admin\Contracts;

use App\Core\System\Http\Request;
use App\Core\System\Http\Response;

/**
 * Публичная граница HTTP-фасада панели (п.2 Конституции).
 *
 * Kernel делегирует ей ВСЮ дорогу /admin/* одной строкой
 * (`$router->any('/admin/{path}', ...)`) — маршрутов, контроллеров и
 * шаблонов админки ядро не знает вовсе.
 */
interface AdminPanelInterface
{
    /** Любое /admin*, /admin/…, /admin/section/action — единая точка входа. */
    public function dispatch(Request $request): Response;
}
