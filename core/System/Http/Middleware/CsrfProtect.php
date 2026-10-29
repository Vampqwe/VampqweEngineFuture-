<?php
declare(strict_types=1);

namespace App\Core\System\Http\Middleware;

use App\Core\System\Http\MiddlewareInterface;
use App\Core\System\Http\Request;
use App\Core\System\Http\Response;
use App\Core\System\Support\ModuleBridge;

/**
 * Глобальный middleware обеих «дорог» (п.4 Конституции): CSRF-защита POST/PUT/PATCH/DELETE.
 * Системная дорога инсталлятора (/install*) исключается — там ещё нет сессий.
 * Токен: поле _csrf в POST или заголовок X-CSRF-Token.
 *
 * Знание о модулях Session/Error — строго через ModuleBridge (правило R6b).
 * Деградация честная (п.9): без Session или Error middleware прозрачно
 * пропускает запрос (Kernel собирает его только когда обе способности есть).
 */
final class CsrfProtect implements MiddlewareInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(
        private readonly ModuleBridge $bridge
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        if (in_array($request->method, self::SAFE_METHODS, true)
            || str_starts_with($request->path, '/install')
            || $request->path === '/setup'
        ) {
            return $next($request);
        }

        $session = $this->bridge->session();
        if ($session === null) {
            return $next($request);
        }

        $token = $request->header('X-CSRF-Token') ?? ($request->post['_csrf'] ?? null);
        if (!$session->validateCsrf(is_string($token) ? $token : null)) {
            // Диагностика для отладки 419 (без утечки секретов — только длины):
            // длина присланного токена и наличие хранимого в сессии. Если
            // stored_len=0 — сессяная запись потеряна (кука/стор), если длины
            // есть, но не равны — форма отрендерена с другим токеном.
            $stored   = $session->get('_csrf');
            $sentLen  = is_string($token) ? strlen($token) : 0;
            $storeLen = is_string($stored) ? strlen($stored) : 0;
            $this->bridge->log(
                'warning',
                "CSRF validation failed: path={$request->path} method={$request->method} sent_len={$sentLen} stored_len={$storeLen}",
                ['channel' => 'Security']
            );

            return new Response($this->bridge->errorPage(419), 419);
        }

        return $next($request);
    }
}
