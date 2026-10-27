<?php
declare(strict_types=1);

namespace App\Core\System\Http\Middleware;

use App\Core\Module\Error\Contracts\ErrorPageInterface;
use App\Core\Module\Session\Contracts\SessionInterface;
use App\Core\System\Http\MiddlewareInterface;
use App\Core\System\Http\Request;
use App\Core\System\Http\Response;

/**
 * Глобальный middleware обеих «дорог» (п.4 Конституции): CSRF-защита POST/PUT/PATCH/DELETE.
 * Системная дорога инсталлятора (/install*) исключается — там ещё нет сессий.
 * Токен: поле _csrf в POST или заголовок X-CSRF-Token.
 */
final class CsrfProtect implements MiddlewareInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(
        private readonly SessionInterface $session,
        private readonly ErrorPageInterface $errorPages
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

        $token = $request->header('X-CSRF-Token') ?? ($request->post['_csrf'] ?? null);
        if (!$this->session->validateCsrf(is_string($token) ? $token : null)) {
            return new Response($this->errorPages->render(419), 419);
        }

        return $next($request);
    }
}
