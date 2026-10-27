<?php
declare(strict_types=1);

namespace App\Core\Module\Exception;

use App\Core\Module\Exception\Contracts\ExceptionHandlerInterface;
use App\Core\Module\Logger\Contracts\LoggerInterface;
use App\Core\System\Http\Request;
use App\Core\System\Http\Response;
use Throwable;

class ExceptionHandler implements ExceptionHandlerInterface
{
    private bool $debug;

    public function __construct(
        private readonly LoggerInterface $logger,
        bool $debug = false
    ) {
        $this->debug = $debug;
    }

    public function report(Throwable $exception): void
    {
        $this->logger->error(
            $exception->getMessage(),
            [
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ]
        );
    }

    public function render(Request $request, Throwable $exception): Response
    {
        // Логируем исключение
        $this->report($exception);

        // Определяем HTTP-статус
        $statusCode = $this->getStatusCode($exception);

        // Формируем ответ
        if ($this->debug) {
            $body = $this->renderDebug($exception);
        } else {
            $body = $this->renderProduction($statusCode);
        }

        return new Response($body, $statusCode);
    }

    private function getStatusCode(Throwable $exception): int
    {
        if (method_exists($exception, 'getStatusCode')) {
            return $exception->getStatusCode();
        }

        return 500;
    }

    private function renderDebug(Throwable $exception): string
    {
        return sprintf(
            '<h1>Ошибка: %d</h1>
             <p><strong>%s</strong></p>
             <p>Файл: %s (строка %d)</p>
             <pre>%s</pre>',
            $this->getStatusCode($exception),
            htmlspecialchars($exception->getMessage()),
            htmlspecialchars($exception->getFile()),
            $exception->getLine(),
            htmlspecialchars($exception->getTraceAsString())
        );
    }

    private function renderProduction(int $statusCode): string
    {
        $messages = [
            404 => 'Страница не найдена',
            403 => 'Доступ запрещён',
            500 => 'Внутренняя ошибка сервера',
            503 => 'Сервис временно недоступен',
        ];

        $message = $messages[$statusCode] ?? 'Произошла ошибка';

        return sprintf(
            '<h1>Ошибка: %d</h1><p>%s</p>',
            $statusCode,
            htmlspecialchars($message)
        );
    }
}