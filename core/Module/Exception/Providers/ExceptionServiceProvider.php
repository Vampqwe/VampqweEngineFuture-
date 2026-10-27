<?php
declare(strict_types=1);

namespace App\Core\Module\Exception\Providers;

use App\Core\System\Container\Contracts\ContainerInterface;
use App\Core\System\Contracts\ModuleProviderInterface;
use App\Core\Module\Config\Contracts\ConfigRepositoryInterface;
use App\Core\Module\Exception\Contracts\ExceptionHandlerInterface;
use App\Core\Module\Exception\ExceptionHandler;
use App\Core\Module\Logger\Contracts\LoggerInterface;
use App\Core\Module\Logger\FallbackLogger;

class ExceptionServiceProvider implements ModuleProviderInterface
{
    private ?ContainerInterface $container = null;

    public function register(ContainerInterface $container): void
    {
        $this->container = $container;

        // Ленивая регистрация: зависимости (Logger, Config) резолвятся при первом обращении,
        // а не в момент boot провайдера. Debug-режим берётся из конфигурации, а не хардкода.
        $container->bind(ExceptionHandlerInterface::class, function (ContainerInterface $c): ExceptionHandlerInterface {
            $debug = false;
            if ($c->has(ConfigRepositoryInterface::class)) {
                $debug = (bool) $c->get(ConfigRepositoryInterface::class)->get('app.debug', false);
            }

            return new ExceptionHandler(
                $c->get(LoggerInterface::class),
                $debug
            );
        });
    }

    public function boot(): void
    {
        set_exception_handler([$this, 'handleException']);
        set_error_handler([$this, 'handleError']);
        // Фатальные ошибки (E_ERROR и т.п.) не бросают исключений —
        // перехватываем их во время остановки скрипта (п.10 Конституции).
        register_shutdown_function([$this, 'handleShutdown']);
    }

    public function handleShutdown(): void
    {
        $error = error_get_last();

        if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }

        $exception = new \ErrorException(
            $error['message'],
            0,
            $error['type'],
            $error['file'],
            $error['line']
        );

        try {
            $handler = $this->container !== null && $this->container->has(ExceptionHandlerInterface::class)
                ? $this->container->get(ExceptionHandlerInterface::class)
                : $this->createFallbackHandler();

            $handler->report($exception);
        } catch (\Throwable) {
            error_log('Fatal during shutdown handling: ' . $error['message']);
        }
    }

    public function handleException(\Throwable $exception): void
    {
        try {
            if ($this->container !== null && $this->container->has(ExceptionHandlerInterface::class)) {
                $handler = $this->container->get(ExceptionHandlerInterface::class);
            } else {
                $handler = $this->createFallbackHandler();
            }

            $handler->report($exception);

            $response = $handler->render(
                \App\Core\System\Http\Request::createFromGlobals(),
                $exception
            );

            $response->send();
        } catch (\Throwable $e) {
            // Если даже обработчик исключений упал — пишем в stderr, без рекурсии
            error_log('Fatal during exception handling: ' . $e->getMessage());
            http_response_code(500);
            echo '<h1>Internal Server Error</h1>';
        }
    }

    /**
     * @param array<string,mixed>|null $context — 5-й параметр обязателен по контракту set_error_handler
     */
    public function handleError(int $severity, string $message, string $file, int $line, ?array $context = null): bool
    {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        throw new \ErrorException($message, 0, $severity, $file, $line);
    }

    private function createFallbackHandler(): ExceptionHandlerInterface
    {
        // Путь к логам берём из контейнера ('storage_path'), а не хардкодом dirname().
        $logPath = ($this->container !== null && $this->container->has('storage_path'))
            ? $this->container->get('storage_path') . '/logs'
            : (defined('BASE_PATH') ? BASE_PATH : getcwd()) . '/storage/logs';

        $logger = new FallbackLogger($logPath);

        // В fallback-режиме всегда показываем детали — основной механизм рендера недоступен
        return new ExceptionHandler($logger, true);
    }
}
