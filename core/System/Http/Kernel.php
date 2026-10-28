<?php
declare(strict_types=1);

namespace App\Core\System\Http;

use App\Core\Module\Config\Contracts\ConfigRepositoryInterface;
use App\Core\Module\Error\Contracts\ErrorPageInterface;
use App\Core\Module\Hook\Contracts\HookInterface;
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
        $userRouter->get('/', fn(Request $r): Response => $this->homeResponse($r));

        // Авторизация (модуль Auth, app/modules): /login, /register, /logout.
        // Подключается ТОЛЬКО при наличии публичного контракта модуля — ядро
        // не знает реализации (п.2). Регистрация ДО /{slug}, чтобы системные
        // слаги auth-дороги не перехватывались страницами контента.
        if ($this->container->has(\App\modules\Auth\Contracts\AuthManagerInterface::class)) {
            /** @var \App\modules\Auth\Contracts\AuthManagerInterface $auth */
            $auth = $this->container->get(\App\modules\Auth\Contracts\AuthManagerInterface::class);
            $userRouter->get('/login', fn(Request $r): Response => $auth->showLogin($r));
            $userRouter->post('/login', fn(Request $r): Response => $auth->login($r));
            $userRouter->get('/register', fn(Request $r): Response => $auth->showRegister($r));
            $userRouter->post('/register', fn(Request $r): Response => $auth->register($r));
            $userRouter->post('/logout', fn(Request $r): Response => $auth->logout($r));
        }

        // Страницы контента (модуль Pages, app/modules): /{slug} отдаёт
        // опубликованную страницу через тему сайта. Подключается ТОЛЬКО если
        // модуль зарегистрирован — ядро не жёстко зависит от приложения (п.2).
        if ($this->container->has(\App\modules\Pages\PageController::class)) {
            /** @var \App\modules\Pages\PageController $pages */
            $pages = $this->container->get(\App\modules\Pages\PageController::class);
            $userRouter->get('/{slug}', fn(Request $r): Response => $pages->show($r));
        }

        $handler = $userRouter->match($request->method, $request->path);

        if ($handler === null) {
            return $this->finish($this->errorPage(404), $session);
        }

        // Хук-шина движка (модуль Hook): перед выполнением пользовательского
        // обработчика прогоняем запрос через фильтр — будущие модули (Auth,
        // Pages, ImportExport) смогут подменить/обработать запрос без правки ядра.
        if ($this->container->has(HookInterface::class)) {
            /** @var HookInterface $hooks */
            $hooks = $this->container->get(HookInterface::class);
            $preempted = $hooks->applyFilters('engine.request', null, $request, $this->container);
            if ($preempted instanceof Response) {
                return $this->finish($preempted, $session);
            }
        }

        $response = $handler($request);

        // Пост-фильтр ответа (расширение без вторжения в ядро).
        if ($this->container->has(HookInterface::class)) {
            /** @var HookInterface $hooks */
            $hooks = $this->container->get(HookInterface::class);
            $response = $hooks->applyFilters('engine.response', $response, $request);
        }

        return $this->finish($response, $session);
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

            $result = $installer->install([
                'app_name'             => (string) ($request->post['app_name'] ?? ''),
                'timezone'             => (string) ($request->post['timezone'] ?? 'UTC'),
                'debug'                => isset($request->post['debug']),
                'super_admin_password' => (string) ($request->post['super_admin_password'] ?? ''),
            ]);

            if ($result === false) {
                return new Response($pages->failurePage('Не удалось записать файлы установки (проверьте права на storage/ и logs/).'), 500);
            }

            $summary = [
                'Название сайта'           => (string) ($request->post['app_name'] ?? 'VampqweEngineFuture'),
                'Часовой пояс'             => (string) ($request->post['timezone'] ?? 'UTC'),
                'Режим отладки'            => isset($request->post['debug']),
                'Супер-администратор'      => \App\Core\System\Support\SuperAdmin::USERNAME . ' (пароль задан, хеш — в .env)',
                '.env создан'              => is_file((string) $this->container->get('base_path') . '/.env'),
            ];
            // Детали Database-шага (создание БД + развёртывание схемы db.sql), если install() их вернул.
            if (is_array($result)) {
                $summary['База данных']    = $result['database'] ?? '—';
                $summary['Схема (db.sql)'] = $result['schema'] ?? '—';
            }

            return new Response($pages->successPage($summary), 201);
        }

        return $this->errorPage(404);
    }

    private function isHealthCheck(Request $request): bool
    {
        return $request->path === '/system/health';
    }

    private function homeResponse(Request $request): Response
    {
        $appName = 'Unknown Engine';
        $debug   = false;

        if ($this->container->has(ConfigRepositoryInterface::class)) {
            $config  = $this->container->get(ConfigRepositoryInterface::class);
            $appName = (string) $config->get('app.name', $appName);
            $debug   = (bool) $config->get('app.debug', false);
        }

        $dbOk = true; $dbDriver = ''; $dbError = null;

        // Честность фундамента: если активирован драйвер Database, а соединения
        // нет — главная НЕ должна рапортовать «успешно запущен».
        if ($this->container->has(\App\Core\Module\Database\Contracts\DatabaseInterface::class)) {
            /** @var \App\Core\Module\Database\Contracts\DatabaseInterface $db */
            $db  = $this->container->get(\App\Core\Module\Database\Contracts\DatabaseInterface::class);
            $dbError = $db->connectionError();
            if ($dbError !== null) {
                $dbOk = false;
                $dbDriver = $db->driver();
            }
        }
        $status = $dbOk ? 200 : 503;

        // Мультисайт: тема сайта по домену запроса — через модуль Theme
        // (реестр engine_sites -> эвристика поддомена -> дефолт из конфига).
        $theme = '';
        if ($this->container->has(\App\Core\Module\Theme\Contracts\ThemeInterface::class)) {
            /** @var \App\Core\Module\Theme\Contracts\ThemeInterface $themes */
            $themes = $this->container->get(\App\Core\Module\Theme\Contracts\ThemeInterface::class);
            $theme  = $themes->resolveForRequest($request->header('host') ?? '');
        } elseif ($this->container->has(\App\Core\Module\Template\Contracts\TemplateInterface::class)) {
            // Fallback без Theme-модуля: прежняя эвристика поддомена.
            $theme = $this->resolveThemeByHost($request->header('host') ?? '');
        }

        // Рендер через модуль Template (view/app/<theme>/home.phtml).
        if ($this->container->has(\App\Core\Module\Template\Contracts\TemplateInterface::class)) {
            /** @var \App\Core\Module\Template\Contracts\TemplateInterface $views */
            $views = $this->container->get(\App\Core\Module\Template\Contracts\TemplateInterface::class);
            try {
                if ($theme !== '') {
                    $views->setTheme($theme);
                }
                $body = $views->render('home', [
                    'appName'    => $appName,
                    'debug'      => $debug,
                    'debugLabel' => $debug ? 'ON' : 'OFF',
                    'dbOk'       => $dbOk,
                    'dbDriver'   => $dbDriver,
                    'dbError'    => $dbError,
                ]);
                return new Response($body, $status);
            } catch (\Throwable $e) {
                // сломанный шаблон не должен ронять движок — уходим в fallback-текст
                if ($this->container->has(\App\Core\Module\Logger\Contracts\LoggerInterface::class)) {
                    $this->container->get(\App\Core\Module\Logger\Contracts\LoggerInterface::class)
                        ->error('Template: рендер home не удался, сработал fallback', ['exception' => $e, 'channel' => 'Template']);
                }
            }
        }

        // Fallback без Template (например, шаблоны ещё не созданы).
        $body = $dbOk
            ? "✅ <b>" . htmlspecialchars($appName, ENT_QUOTES) . "</b> (Debug: " . ($debug ? 'ON' : 'OFF') . ") успешно запущен!"
            : "⚠️ <b>" . htmlspecialchars($appName, ENT_QUOTES) . "</b>: база данных «" . htmlspecialchars($dbDriver, ENT_QUOTES) . "» недоступна.";
        return new Response($body, $status);
    }

    /**
     * Мультисайт: сопоставляет хост запроса с темой view/app/.
     * Временная эвристика до появления таблицы sites: поддомен/домен вида
     * animals.localhost или animals.local -> тема 'animals'.
     */
    private function resolveThemeByHost(string $host): string
    {
        $host = strtolower(preg_replace('/:\d+$/', '', $host) ?? $host);
        if ($host === '' || $host === 'localhost' || str_contains($host, '127.0.0.1')) {
            return '';
        }
        $first = explode('.', $host)[0];
        if (preg_match('#^[a-z0-9_-]+$#', $first) && $first !== 'www') {
            return $first;
        }
        return '';
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
            $connected = $db->ping();
            $payload['database'] = ['driver' => $db->driver(), 'connected' => $connected];

            // Если БД заявлена активным драйвером и недоступна — health не «ok»:
            // статус degraded + HTTP 503 (принцип честности фундамента).
            if (!$connected) {
                $payload['status'] = 'degraded';
                if ($debug = $this->container->has(ConfigRepositoryInterface::class)
                    && (bool) $this->container->get(ConfigRepositoryInterface::class)->get('app.debug', false)) {
                    $payload['database']['error'] = $db->connectionError();
                }
            }
        }

        return new Response(
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}',
            ($payload['status'] ?? 'ok') === 'ok' ? 200 : 503,
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
