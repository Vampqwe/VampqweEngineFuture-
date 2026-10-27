<?php
declare(strict_types=1);

namespace App\Core\Module\Error;

use App\Core\Module\Error\Contracts\ErrorPageInterface;

/**
 * Фасад модуля Error.
 *
 * Зависимости: Template (красивые страницы), Config (app.debug — показ трейса).
 * Сейчас отдаёт минимальные встроенные страницы; подключение Template — в плане.
 */
final class ErrorPageRenderer implements ErrorPageInterface
{
    /** @var array<int,string> */
    private array $templates = [];

    public function __construct(private readonly bool $debug = false)
    {
    }

    public function render(int $statusCode, ?\Throwable $exception = null): string
    {
        $title = match ($statusCode) {
            404 => 'Страница не найдена',
            503 => 'Сервис временно недоступен',
            419 => 'Сессия истекла или токен безопасности недействителен',
            default => 'Внутренняя ошибка сервера',
        };

        $trace = '';
        if ($this->debug && $exception !== null) {
            $trace = '<pre>' . htmlspecialchars($exception->getMessage(), ENT_QUOTES) . '</pre>';
        }

        return "<!doctype html><html lang=\"ru\"><head><meta charset=\"utf-8\"><title>{$statusCode}</title></head>"
             . "<body><h1>{$statusCode} — {$title}</h1>{$trace}</body></html>";
    }

    public function extend(int $statusCode, string $template): void
    {
        $this->templates[$statusCode] = $template;
    }
}
