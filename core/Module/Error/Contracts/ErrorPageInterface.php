<?php
declare(strict_types=1);

namespace App\Core\Module\Error\Contracts;

/**
 * Контракт модуля Error — генерация красивых HTTP-страниц ошибок (404/500/503).
 * Отличается от Exception: Exception логирует и перехватывает, Error РЕНДЕРИТ страницу.
 */
interface ErrorPageInterface
{
    /** Отрендерить HTML страницы ошибки по HTTP-коду. */
    public function render(int $statusCode, ?\Throwable $exception = null): string;

    /** Зарегистрировать пользовательский шаблон для кода (например, 404 => 'errors/my-404'). */
    public function extend(int $statusCode, string $template): void;
}
