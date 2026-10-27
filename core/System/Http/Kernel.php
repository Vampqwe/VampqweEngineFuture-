<?php
declare(strict_types=1);

namespace App\Core\System\Http;

use App\Core\Module\Config\Contracts\ConfigRepositoryInterface;
use App\Core\System\Container\Contracts\ContainerInterface;
use App\Core\System\Enums\AppState;
use App\Core\System\Support\Router;

class Kernel
{
    public function __construct(
        private readonly ContainerInterface $container
    ) {}

    public function handle(Request $request): Response
    {
        $state = $this->detectAppState();

        // Для состояний вне Running отдаём служебные страницы,
        // игнорируя маршрутизацию (кроме /system/health — он доступен всегда).
        if ($state !== AppState::Running && !$this->isHealthCheck($request)) {
            return $this->stateResponse($state);
        }

        // Обычная маршрутизация: две «дороги» — системная и пользовательская.
        // Системная дорога (/system/*, /admin/*, /api/system/*) — маршруты ядра.
        $systemRouter = new Router();
        $systemRouter->get('/system/health', fn(Request $r): Response => $this->healthResponse($r));

        $handler = $systemRouter->match($request->method, $request->path);

        if ($handler === null && $this->isSystemPath($request->path)) {
            return new Response('<h1>Ошибка: 404</h1><p>Системный маршрут не найден</p>', 404);
        }

        if ($handler !== null) {
            return $handler($request);
        }

        // Пользовательская дорога (/, /blog/*, ...) со своими глобальными middleware.
        $userRouter = new Router([
            new \App\Core\System\Http\Middleware\MaintenanceMode(
                $this->container->get('storage_path'),
                $state
            ),
        ]);
        $userRouter->get('/', fn(Request $r): Response => $this->homeResponse());

        $handler = $userRouter->match($request->method, $request->path);

        if ($handler === null) {
            return new Response('<h1>Ошибка: 404</h1><p>Страница не найдена</p>', 404);
        }

        return $handler($request);
    }

    private function isSystemPath(string $path): bool
    {
        return str_starts_with($path, '/system/')
            || str_starts_with($path, '/admin')
            || str_starts_with($path, '/api/system');
    }

    private function isHealthCheck(Request $request): bool
    {
        return $request->path === '/system/health';
    }

    private function homeResponse(): Response
    {
        $appName = 'Unknown Engine';
        $debug   = false;

        if ($this->container->has(ConfigRepositoryInterface::class)) {
            $config  = $this->container->get(ConfigRepositoryInterface::class);
            $appName = (string) $config->get('app.name', $appName);
            $debug   = (bool) $config->get('app.debug', false);
        }

        $debugLabel = $debug ? 'ON' : 'OFF';

        return new Response(
            "✅ <b>{$appName}</b> (Debug: {$debugLabel}) успешно запущен!",
            200
        );
    }

    private function healthResponse(Request $request): Response
    {
        $storagePath = $this->container->get('storage_path');

        $payload = [
            'status'  => 'ok',
            'state'   => $this->detectAppState()->value,
            'php'     => PHP_VERSION,
            'storage_writable' => is_writable($storagePath),
            'time'    => date('c'),
        ];

        return new Response(
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}',
            200,
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    private function stateResponse(AppState $state): Response
    {
        $appName = 'Unknown Engine';
        $debug   = false;

        if ($this->container->has(ConfigRepositoryInterface::class)) {
            $config  = $this->container->get(ConfigRepositoryInterface::class);
            $appName = (string) $config->get('app.name', $appName);
            $debug   = (bool) $config->get('app.debug', false);
        }

        $debugLabel = $debug ? 'ON' : 'OFF';

        // Все ветки enum AppState покрыты — UnhandledMatchError невозможен.
        // 503 (Service Unavailable) — корректный код для временной недоступности.
        return match ($state) {
            AppState::NotInstalled => new Response(
                "🚀 <b>{$appName}</b> (Debug: {$debugLabel})<br>Движок не установлен. Запускаем Installer...",
                503
            ),
            AppState::Installing   => new Response('⚙️ Идет процесс установки...', 503),
            AppState::Maintenance  => new Response('🔧 Сайт находится в режиме технического обслуживания.', 503),
            AppState::Upgrading    => new Response('⬆️ Идет обновление движка...', 503),
            AppState::Running      => $this->homeResponse(),
            AppState::Error        => new Response('❌ Критическая ошибка приложения.', 500),
        };
    }

    private function detectAppState(): AppState
    {
        $storagePath = $this->container->get('storage_path');

        if (!file_exists($storagePath . '/.installed')) {
            return AppState::NotInstalled;
        }
        if (file_exists($storagePath . '/.installing')) {
            return AppState::Installing;
        }
        if (file_exists($storagePath . '/.upgrading')) {
            return AppState::Upgrading;
        }
        if (file_exists($storagePath . '/.maintenance')) {
            return AppState::Maintenance;
        }
        if (file_exists($storagePath . '/.error')) {
            return AppState::Error;
        }

        return AppState::Running;
    }
}
