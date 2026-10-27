<?php
declare(strict_types=1);

namespace App\Core\System\Http;

/**
 * Контракт middleware для «дорог» маршрутизации (системная / пользовательская).
 * Middleware получает запрос и замыкание «продолжить обработку».
 */
interface MiddlewareInterface
{
    public function process(Request $request, callable $next): Response;
}
