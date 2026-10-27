<?php
declare(strict_types=1);

namespace App\Core\System\Http;

use App\Core\Module\Config\Contracts\ConfigRepositoryInterface;
use App\Core\Module\Error\Contracts\ErrorPageInterface;
use App\Core\Module\Installer\Contracts\InstallerInterface;
use App\Core\Module\Installer\Contracts\InstallerPageInterface;
use App\Core\Module\Session\Contracts\SessionInterface;
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

        // Session: ленивое жизненное пространство запроса. Резолвим только когда
        // модуль доступен (до установки его нет — и это нормально).
        $session = $this->container->has(SessionInterface::class)
            ? $this->container->get(SessionInterface::class)
            : null;

        if ($session !== null) {
            // Однократная фоновая миграция file<->redis при СМЕНЕ session.driver:
            // оставшиеся записи старого стора переносятся в новый без потери сессий.
            $this->migrateSessionStoreOnce($session);
        }

        // Инсталлятор живёт на системной дороге и доступен при любом состоянии:
        // до установки это единственный способ довести движок до Running.
        if ($this->isInstallerPath($request->path)) {
            return $this->installerResponse($request);
        }

        // Для состояний вне Running отдаём служебные страницы,
        // игнорируя маршрутизацию (кроме /system/health — он доступен всегда).
        if ($state !== AppState::Running && !$this->isHealthCheck($request)) {
            return $this->finish($this->stateResponse($state), $session);
        }

        // Обычная маршрутизация: две «дороги» — системная и пользовательская.
        // Системная дорога (/system/*, /admin/*, /api/system/*) — маршруты ядра.
        $systemRouter = new Router($this->globalMiddleware($request));
        $systemRouter->get('/system/health', fn(Request $r): Response => $this->healthResponse($r));

        $handler = $systemRouter->match($request->method, $request->path);

        if ($handler === null && $this->isSystemPath($request->path)) {
            return $this->finish($this->errorPage(404), $session);
        }

        if ($handler !== null) {
            return $this->finish($handler($request), $session);
        }

        // Пользовательская дорога (/, /blog/*, ...) со своими глобальными middleware.
        $userRouter = new Router(array_merge(
            $this->globalMiddleware($request),
            [new \App\Core\System\Http\Middleware\MaintenanceMode(
                $this->container->get(ErrorPageInterface::class),
                $state
            )],
        ));
        $userRouter->get('/', fn(Request $r): Response => $this->homeResponse());

        $handler = $userRouter->match($request->method, $request->path);

        if ($handler === null) {
            return $this->finish($this->errorPage(404), $session);
        }

        return $this->finish($handler($request), $session);
    }

    /**
     * Глобальные middleware обеих дорог (п.4 Конституции): CSRF-защита.
     * Подключается только если доступны Session и Error (после установки).
     */
    private function globalMiddleware(Request $request): array
    {
        if (!$this->container->has(SessionInterface::class)) {
            return [];
        }

        return [new \App\Core\System\Http\Middleware\CsrfProtect(
            $this->container->get(SessionInterface::class),
            $this->container->get(ErrorPageInterface::class),
        )];
    }

    /** Завершение запроса: сохранить сессию + выдать куку, если надо. */
    private function finish(Response $response, ?SessionInterface $session): Response
    {
        if ($session === null) {
            return $response;
        }

        try {
            $session->save();
            if (!$session->clientHasValidCookie() && $session->id() !== '') {
                $response = $response->withCookie($session->sessionCookieHeader());
            }
        } catch (\Throwable $e) {
            // сессия не должна ронять ответ — пишем в лог и продолжаем
            if ($this->container->has(\App\Core\Module\Logger\Contracts\LoggerInterface::class)) {
                $this->container->get(\App\Core\Module\Logger\Contracts\LoggerInterface::class)
                    ->error('Session save failed: ' . $e->getMessage(), ['channel' => 'Session']);
            }
        }

        return $response;
    }

    /**
     * Фоновая миграция хранилища сессий после смены session.driver.
     * Маркер — в storage/.session_store.<driver>; выполняется один раз на драйвер.
     */
    private function migrateSessionStoreOnce(SessionInterface $session): void
    {
        $driver  = $session->driver();
        $storage = (string) $this->container->get('storage_path');
        $marker  = $storage . '/.session_store.' . $driver;
        if (is_file($marker)) {
            return;
        }

        $other = $driver === 'redis' ? 'file' : 'redis';
        try {
            $moved = $session->migrateStore($other, $driver);
            @file_put_contents($marker, (string) time());
            if ($moved > 0 && $this->container->has(\App\Core\Module\Logger\Contracts\LoggerInterface::class)) {
                $this->container->get(\App\Core\Module\Logger\Contracts\LoggerInterface::class)->info(
                    "Миграция сессий {$other} -> {$driver}: перенесено {$moved}",
                    ['channel' => 'Session']
                );
            }
        } catch (\Throwable) {
            // недоступный стор-источник — миграция не нужна, помечаем выполненной
            @file_put_contents($marker, (string) time());
        }
    }

    /**
     * Служебные страницы отдаёт модуль Error (контракт ErrorPageInterface).
     * Если модуль недоступен (ранний бутстрап) — минимальный fallback.
     */
    private function errorPage(int $code, ?\Throwable $e = null): Response
    {
        if ($this->container->has(ErrorPageInterface::class)) {
            return new Response(
                $this->container->get(ErrorPageInterface::class)->render($code, $e),
                $code
            );
        }

        return new Response('<h1>Ошибка: ' . $code . '</h1>', $code);
    }

    private function isSystemPath(string $path): bool
    {
        return str_starts_with($path, '/system/')
            || str_starts_with($path, '/admin')
            || str_starts_with($path, '/api/system')
            || $this->isInstallerPath($path);
    }

    private function isInstallerPath(string $path): bool
    {
        return $path === '/install'
            || str_starts_with($path, '/install/')
            || $path === '/setup';
    }

    /**
     * Системная дорога инсталлятора: GET /install (шаг 1), POST /install/settings (шаг 2),
     * POST /install/run (выполнение установки). Вся логика — через контракты InstallerInterface
     * и InstallerPageInterface (п.2/п.3 Конституции), HTML формирует модуль Installer.
     */
    private function installerResponse(Request $request): Response
    {
        if (!$this->container->has(InstallerInterface::class)) {
            return new Response('<h1>503</h1><p>Модуль Installer недоступен.</p>', 503);
        }

        $installer = $this->container->get(InstallerInterface::class);
        $pages     = $this->container->get(InstallerPageInterface::class);
        assert($pages instanceof InstallerPageInterface);

        $path   = $request->path;
        $method = $request->method;

        // Шаг 1: требования окружения
        if (($path === '/install' || $path === '/setup') && $method === 'GET') {
            if ($installer->isInstalled()) {
                return new Response($pages->successPage(['Движок уже установлен' => 'да', 'Открыть сайт' => '/']), 200);
            }
            return new Response($pages->requirementsPage($installer->requirements()), 200);
        }

        // Шаг 2: форма настроек
        if ($path === '/install/settings' && $method === 'POST') {
            $errors = \App\Core\Module\Installer\Installer::validateSettings($request->post);
            return new Response($pages->settingsForm($errors), $errors === [] ? 200 : 422);
        }

        // Шаг 3: выполнение установки
        if ($path === '/install/run' && $method === 'POST') {
            $errors = \App\Core\Module\Installer\Installer::validateSettings($request->post);
            if ($errors !== []) {
                return new Response($pages->settingsForm($errors), 422);
            }

            $ok = $installer->install([
                'app_name'             => (string) ($request->post['app_name'] ?? ''),
                'timezone'             => (string) ($request->post['timezone'] ?? 'UTC'),
                'debug'                => isset($request->post['debug']),
                'super_admin_password' => (string) ($request->post['super_admin_password'] ?? ''),
            ]);

            if (!$ok) {
                return new Response($pages->failurePage('Не удалось записать файлы установки (проверьте права на storage/ и logs/).'), 500);
            }

            return new Response($pages->successPage([
                'Название сайта'           => (string) ($request->post['app_name'] ?? 'VampqweEngineFuture'),
                'Часовой пояс'             => (string) ($request->post['timezone'] ?? 'UTC'),
                'Режим отладки'            => isset($request->post['debug']),
                'Супер-администратор'      => \App\Core\System\Support\SuperAdmin::USERNAME . ' (пароль задан, хеш — в .env)',
                '.env создан'              => is_file((string) $this->container->get('base_path') . '/.env'),
            ]), 201);
        }

        return $this->errorPage(404);
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

        // Database: честный статус PDO-соединения (ping() не бросает исключений).
        if ($this->container->has(\App\Core\Module\Database\Contracts\DatabaseInterface::class)) {
            /** @var \App\Core\Module\Database\Contracts\DatabaseInterface $db */
            $db = $this->container->get(\App\Core\Module\Database\Contracts\DatabaseInterface::class);
            $payload['database'] = ['driver' => $db->driver(), 'connected' => $db->ping()];
        }

        return new Response(
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}',
            200,
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    private function stateResponse(AppState $state): Response
    {
        // Состояния вне Running — временная недоступность (503), ошибка — 500.
        // Текст страницы формирует модуль Error; если он недоступен — fallback.
        $code = $state === AppState::Error ? 500 : 503;

        $title = match ($state) {
            AppState::NotInstalled => '🚀 Движок не установлен. Перейдите на <a href="/install" style="color:#a9c7ff">/install</a> — запустится установщик.',
            AppState::Installing   => '⚙️ Идет процесс установки...',
            AppState::Maintenance  => '🔧 Сайт находится в режиме технического обслуживания.',
            AppState::Upgrading    => '⬆️ Идет обновление движка...',
            AppState::Running      => '', // сюда не попадаем: Running маршрутизируется обычно
            AppState::Error        => '❌ Критическая ошибка приложения.',
        };

        if ($this->container->has(ErrorPageInterface::class)) {
            $body = $this->container->get(ErrorPageInterface::class)->render($code);
            // Служебное сообщение состояния добавляем к стандартной странице.
            $body = str_replace('</body>', '<p>' . $title . '</p></body>', $body);

            return new Response($body, $code);
        }

        return new Response('<h1>' . $code . '</h1><p>' . $title . '</p>', $code);
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
