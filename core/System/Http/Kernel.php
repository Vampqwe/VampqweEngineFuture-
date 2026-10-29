<?php
declare(strict_types=1);

namespace App\Core\System\Http;

use App\Core\System\Container\Contracts\ContainerInterface;
use App\Core\System\Enums\AppState;
use App\Core\System\Http\Middleware\CsrfProtect;
use App\Core\System\Http\Middleware\MaintenanceMode;
use App\Core\System\Support\KernelDispatcher;
use App\Core\System\Support\ModuleBridge;
use App\Core\System\Support\Router;
use App\Core\System\Support\SiteIdentity;
use App\Core\System\Support\SuperAdmin;

/**
 * HTTP-конвейер ядра. Знание о модулях — ТОЛЬКО через ModuleBridge (п.1/п.2,
 * правило R6b линтера): в executable-коде этого файла нет ни одной ссылки
 * `App\...\Module\<X>::class` / `App\modules\<X>::class`. Ядро оркестрирует
 * состояния, дороги и хуки; реализации живут за контрактами.
 *
 * Декомпозиция SRP: рендер главной страницы вынесен в KernelDispatcher,
 * резолв мультисайт-контекста — в siteContext(). handle() = конвейер.
 */
class Kernel
{
    private readonly ModuleBridge $bridge;

    public function __construct(
        private readonly ContainerInterface $container
    ) {
        $this->bridge = new ModuleBridge($container);
    }

    public function handle(Request $request): Response
    {
        $state = $this->detectAppState();

        // Session: ленивое жизненное пространство запроса. Резолвим только когда
        // модуль доступен (до установки его нет — и это нормально).
        $session = $this->bridge->session();

        if ($session !== null) {
            // Однократная фоновая миграция file<->redis при СМЕНЕ session.driver:
            // оставшиеся записи старого стора переносятся в новый без потери сессий.
            $this->migrateSessionStoreOnce($session);
        }

        // П.4 Конституции: модули/темы регистрируют ассеты через хук-действие
        // без правки движка (после boot контейнер уже содержит Hook + Assets).
        $hooks  = $this->bridge->hooks();
        $assets = $this->bridge->assets();
        if ($hooks !== null && $assets !== null) {
            $hooks->dispatch('engine.assets.registered', $assets);
        }

        // Инсталлятор живёт на системной дороге и доступен при любом состоянии:
        // до установки это единственный способ довести движок до Running.
        // Исключение из «любого состояния» — Installing: во время POST /install/run
        // install() создаёт storage/.installing (внутри одного запроса), а повторный
        // вход в installerResponse вызвал бы requirements()/validateSettings() с
        // побочным эффектом AppState::detect()==Installing -> 503 «Идет установка».
        // На время собственного выполнения установщика его страницы не перезаходятся;
        // для GET-запросов флаг Installing физически не может стоять без зависшей
        // установки — их пускаем как раньше (страницы прогресса).
        if ($this->isInstallerPath($request->path)
            && !($state === AppState::Installing && $request->method === 'POST')) {
            // Единая системная тема (engine.css) для страниц установщика:
            // реестр Assets уже резолвится через Bridge выше ($assets).
            // Публикация тегов — опциональна: без Template установщик
            // отдаёт legacy-HTML со встроенными стилями (п.9: не падать).
            if ($assets !== null) {
                $assets->registerStyle('engine', 'css/engine.css', [], \App\Core\Module\Assets\Contracts\AssetsInterface::SCOPE_CORE);
                $tpl = $this->bridge->templates();
                if ($tpl !== null) {
                    $tpl->share('assetsStyles', $assets->styles());
                }
            }
            return $this->installerResponse($request);
        }

        // Статика (модуль Assets): /assets/<path> раздаётся PHP из каскада
        // site-sandbox -> theme -> core ДО проверки состояния — инсталлятору
        // и страницам ошибок свои CSS нужны и в NotInstalled/Maintenance.
        if (str_starts_with($request->path, '/assets/') && $assets !== null) {
            return $this->finish($this->assetResponse($request, $assets), $session);
        }

        // Для состояний вне Running отдаём служебные страницы,
        // игнорируя маршрутизацию (кроме /system/health — он доступен всегда).
        if ($state !== AppState::Running && !$this->isHealthCheck($request)) {
            return $this->finish($this->stateResponse($state), $session);
        }

        // Обычная маршрутизация: две «дороги» — системная и пользовательская.
        // Системная дорога (/system/*, /admin/*, /api/system/*) — маршруты ядра.
        $systemRouter = new Router($this->globalMiddleware());
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
            $this->globalMiddleware(),
            $this->maintenanceMiddleware($state),
        ));

        // Идентичность сайта (BASE_URL_SITE / SITE_DOMAIN): «один запуск =
        // один сайт». Логика — в SiteIdentity (чистые функции), доступ к
        // конфигу — через ModuleBridge (R6b). Пустая идентичность = легаси-
        // режим: хост не проверяется, сайт резолвится реестром engine_sites.
        $identity  = $this->bridge->siteDomain();
        $strict    = $identity !== '' && $this->bridge->siteStrictHost();
        $homeRoute = function (Request $r) use ($identity, $strict): Response {
            if ($strict && !SiteIdentity::hostMatches($identity, (string) ($r->header('host') ?? ''))) {
                // Чужой хост обслуживает НЕ этот экземпляр движка -> 404.
                return $this->errorPage(404);
            }

            return $this->homeResponse($r);
        };
        $userRouter->get('/', $homeRoute);
        // Домен из конфигурации — канонический адрес сайта; обращаемый и как
        // '/index.php' (некоторые хостинги без rewrite прокидывают его в URI).
        $userRouter->get('/index.php', $homeRoute);

        $this->registerModuleRoutes($userRouter);

        $handler = $userRouter->match($request->method, $request->path);

        if ($handler === null) {
            return $this->finish($this->errorPage(404), $session);
        }

        // Хук-шина движка (модуль Hook): перед выполнением пользовательского
        // обработчика прогоняем запрос через фильтр — будущие модули (Auth,
        // Pages, ImportExport) смогут подменить/обработать запрос без правки ядра.
        if ($hooks !== null) {
            $preempted = $hooks->applyFilters('engine.request', null, $request, $this->container);
            if ($preempted instanceof Response) {
                return $this->finish($preempted, $session);
            }
        }

        $response = $handler($request);

        // Пост-фильтр ответа (расширение без вторжения в ядро).
        if ($hooks !== null) {
            $filtered = $hooks->applyFilters('engine.response', $response, $request);
            if ($filtered instanceof Response) {
                $response = $filtered;
            }
        }

        return $this->finish($response, $session);
    }

    /**
     * Глобальные middleware обеих дорог (п.4 Конституции): CSRF-защита.
     * Подключается только если доступны Session и Error (после установки).
     */
    private function globalMiddleware(): array
    {
        if (!$this->bridge->hasErrorPages()) {
            return [];
        }
        $session = $this->bridge->session();
        if ($session === null) {
            return [];
        }

        $errors = $this->bridge->errorPages();
        if ($errors === null) {
            return [];
        }

        return [new CsrfProtect($this->bridge)];
    }

    /** Middleware режима обслуживания — только если состояние Maintenance и Error доступен. */
    private function maintenanceMiddleware(AppState $state): array
    {
        $errors = $this->bridge->errorPages();
        if ($state !== AppState::Maintenance || $errors === null) {
            return [];
        }

        return [new MaintenanceMode($this->bridge, $state)];
    }

    /**
     * Регистрация дорог модулей-контрактов (п.1: удаление/переименование
     * модуля приложения не касается ядра — просто нет bind'а, просто нет дороги).
     */
    private function registerModuleRoutes(Router $userRouter): void
    {
        // Авторизация (модуль Auth, app/modules): /login, /register, /logout.
        // Регистрация ДО /{slug}, чтобы системные слаги auth-дороги не
        // перехватывались страницами контента.
        $auth = $this->bridge->authManager();
        if ($auth !== null) {
            $userRouter->get('/login', fn(Request $r): Response => $auth->showLogin($r));
            $userRouter->post('/login', fn(Request $r): Response => $auth->login($r));
            $userRouter->get('/register', fn(Request $r): Response => $auth->showRegister($r));
            $userRouter->post('/register', fn(Request $r): Response => $auth->register($r));
            $userRouter->post('/logout', fn(Request $r): Response => $auth->logout($r));
        }

        // Панель администратора (СИСТЕМНЫЙ модуль Admin): ВСЯ дорога /admin/*
        // делегируется контракту AdminPanelInterface — ядро не знает
        // маршрутов/разделов/шаблонов панели (п.2). Регистрация ДО /{slug}.
        $admin = $this->bridge->adminPanel();
        if ($admin !== null) {
            $adminRoute = fn(Request $r): Response => $admin->dispatch($r);
            $userRouter->any('/admin', $adminRoute);
            $userRouter->any('/admin/{tail*}', $adminRoute);
        }

        // Страницы контента (модуль Pages, app/modules): /{slug} отдаёт
        // опубликованную страницу через публичный HTTP-контракт модуля.
        $pages = $this->bridge->pageRenderer();
        if ($pages !== null) {
            $userRouter->get('/{slug}', fn(Request $r): Response => $pages->show($r));
        }
    }

    /** Завершение запроса: сохранить сессию + выдать куку, если надо. */
    private function finish(Response $response, ?object $session): Response
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
            $this->bridge->log('error', 'Session save failed: ' . $e->getMessage(), ['channel' => 'Session']);
        }

        return $response;
    }

    /**
     * Фоновая миграция хранилища сессий после смены session.driver.
     * Маркер — в storage/.session_store.<driver>; выполняется один раз на драйвер.
     */
    private function migrateSessionStoreOnce(object $session): void
    {
        // Capability-проверка (п.3): миграция сторов — внутренняя механика
        // Session, доступная через отдельный контракт; ядро не знает ни
        // ModuleSession, ни имён сторов — только способность (через Bridge).
        $migrator = $this->bridge->storeMigrator();
        if ($migrator === null) {
            return;
        }

        $driver  = $session->driver();
        $storage = $this->bridge->path('storage_path');
        $marker  = AppState::sessionStoreMarker($storage, $driver);
        if (is_file($marker)) {
            return;
        }

        $other = $driver === 'redis' ? 'file' : 'redis';
        try {
            $moved = $migrator->migrateStore($other, $driver);
            @file_put_contents($marker, (string) time());
            if ($moved > 0) {
                $this->bridge->log('info', "Миграция сессий {$other} -> {$driver}: перенесено {$moved}", ['channel' => 'Session']);
            }
        } catch (\Throwable) {
            // недоступный стор-источник — миграция не нужна, помечаем выполненной
            @file_put_contents($marker, (string) time());
        }
    }

    /** Служебные страницы формирует модуль Error (через Bridge); fallback — минимальный HTML. */
    private function errorPage(int $code, ?\Throwable $e = null): Response
    {
        return new Response($this->bridge->errorPage($code, $e), $code);
    }

    private function isSystemPath(string $path): bool
    {
        return str_starts_with($path, '/system/')
            || str_starts_with($path, '/admin')
            || str_starts_with($path, '/api/system')
            || $this->isInstallerPath($path);
    }

    /**
     * Раздача статики (модуль Assets): /assets/<path> -> каскад
     * site-sandbox -> theme -> core. Контекст домена/темы выставляется здесь же
     * (best effort: без Theme-модуля/БД — только CORE-каскад). Отсутствующий
     * файл — 404 через модуль Error (п.9: не падать).
     */
    private function assetResponse(Request $request, object $assets): Response
    {
        [$theme, $domain] = $this->siteContext($request);
        $assets->useContext($domain, $theme !== '' ? $theme : $this->bridge->defaultTheme());

        $file = $assets->serve(substr($request->path, strlen('/assets/')));
        if ($file === null) {
            return $this->errorPage(404);
        }

        // Кэш-валидация: ETag по содержимому + Last-Modified по mtime файла.
        // Условный запрос (If-None-Match / If-Modified-Since) -> 304 без тела:
        // max-age 86400 + ревалидация = мгновенная раздача после изменения файла
        // (?v= в URL тоже ломает кэш, см. AssetsRegistry::url).
        $etag  = '"' . md5($file['body']) . '"';
        $mtime = is_file($file['path']) ? (int) filemtime($file['path']) : time();
        $headers = [
            'Content-Type'  => $file['mime'],
            'Cache-Control' => 'public, max-age=86400',
            'ETag'          => $etag,
            'Last-Modified' => gmdate('D, d M Y H:i:s', $mtime) . ' GMT',
        ];

        $inm = (string) ($request->header('if-none-match') ?? '');
        $ims = (string) ($request->header('if-modified-since') ?? '');
        if ($inm !== '' && ($inm === '*' || str_contains($inm, $etag))) {
            return new Response('', 304, array_intersect_key($headers, array_flip(['ETag', 'Last-Modified'])));
        }
        if ($inm === '' && $ims !== '') {
            $since = strtotime($ims);
            if ($since !== false && $mtime <= $since) {
                return new Response('', 304, array_intersect_key($headers, array_flip(['ETag', 'Last-Modified'])));
            }
        }

        return (new Response($file['body'], 200))
            ->withHeader('Content-Type', $headers['Content-Type'])
            ->withHeader('Cache-Control', $headers['Cache-Control'])
            ->withHeader('ETag', $headers['ETag'])
            ->withHeader('Last-Modified', $headers['Last-Modified']);
    }

    /**
     * Мультисайт-контекст запроса: тема + домен сайта через резолв Bridge.
     *
     * Источник домена (в порядке приоритета):
     *   1. идентичность из конфигурации (SITE_DOMAIN / хост BASE_URL_SITE) —
     *      «один запуск = один сайт», путаницы с Host-заголовком нет;
     *   2. пустая идентичность -> легаси: Host запроса + реестр engine_sites.
     * Без Theme-модуля — прежняя эвристика поддомена (best effort, п.9).
     *
     * @return array{0: string, 1: ?string} [theme, domain]
     */
    private function siteContext(Request $request): array
    {
        $host = SiteIdentity::normalizeHost((string) ($request->header('host') ?? ''));

        // Конфигурационная идентичность важнее заголовка запроса.
        $configured = $this->bridge->siteDomain();
        if ($configured !== '') {
            $host = $configured;
        }

        $themes = $this->bridge->themes();
        if ($themes === null) {
            return [$this->resolveThemeByHost($host), null];
        }

        $theme = $themes->resolveForRequest($host);
        $site  = $themes->site();

        return [$theme, $site['domain'] ?? null];
    }

    private function isInstallerPath(string $path): bool
    {
        return $path === '/install'
            || str_starts_with($path, '/install/')
            || $path === '/setup';
    }

    /**
     * Системная дорога инсталлятора: GET /install (шаг 1), POST /install/settings (шаг 2),
     * POST /install/run (выполнение установки). Вся логика — через контракты
     * модуля Installer (п.2/п.3, резолв через Bridge), HTML формирует сам модуль.
     */
    private function installerResponse(Request $request): Response
    {
        $installer = $this->bridge->installer();
        $pages     = $this->bridge->installerPages();
        if ($installer === null || $pages === null) {
            return new Response('<h1>503</h1><p>Модуль Installer недоступен.</p>', 503);
        }

        $path   = $request->path;
        $method = $request->method;

        // Шаг 1: требования окружения
        if (($path === '/install' || $path === '/setup') && $method === 'GET') {
            if ($installer->isInstalled()) {
                return new Response($pages->successPage(['Движок уже установлен' => 'да', 'Открыть сайт' => '/']), 200);
            }
            return new Response($pages->requirementsPage($installer->requirements()), 200);
        }

        // Шаг 2: форма настроек. Валидация — через контракт InstallerInterface (п.2).
        if ($path === '/install/settings' && $method === 'POST') {
            $errors = $installer->validateSettings($request->post);
            return new Response($pages->settingsForm($errors), $errors === [] ? 200 : 422);
        }

        // Шаг 3: выполнение установки
        if ($path === '/install/run' && $method === 'POST') {
            $errors = $installer->validateSettings($request->post);
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
                'Супер-администратор'      => SuperAdmin::USERNAME . ' (пароль задан, хеш — в .env)',
                '.env создан'              => is_file($this->bridge->path('env_path')),
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

    /**
     * Рендер главной — делегирован KernelDispatcher (SRP: ядро не является
     * контроллером; оркестрация дорог остаётся здесь).
     */
    private function homeResponse(Request $request): Response
    {
        return (new KernelDispatcher($this->bridge))->home($request);
    }

    private function healthResponse(Request $request): Response
    {
        $storagePath = $this->bridge->path('storage_path');

        $payload = [
            'status'  => 'ok',
            'state'   => $this->detectAppState()->value,
            'php'     => PHP_VERSION,
            'storage_writable' => is_writable($storagePath),
            'time'    => date('c'),
        ];

        // Database: честный статус PDO-соединения (ping() не бросает исключений).
        $db = $this->bridge->database();
        if ($db !== null) {
            $connected = $db->ping();
            $payload['database'] = ['driver' => $db->driver(), 'connected' => $connected];

            // Если БД заявлена активным драйвером и недоступна — health не «ok»:
            // статус degraded + HTTP 503 (принцип честности фундамента).
            if (!$connected) {
                $payload['status'] = 'degraded';
                if ((bool) $this->bridge->configValue('app.debug', false)) {
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

        if ($this->bridge->hasErrorPages()) {
            $body = $this->bridge->errorPage($code);
            // Служебное сообщение состояния добавляем к стандартной странице.
            $body = str_replace('</body>', '<p>' . $title . '</p></body>', $body);

            return new Response($body, $code);
        }

        return new Response('<h1>' . $code . '</h1><p>' . $title . '</p>', $code);
    }

    /**
     * Детект состояния движка — делегация единому резолверу AppState::detect()
     * (п.10: одна реализация, имена маркеров только в AppState::flagNames()).
     */
    private function detectAppState(): AppState
    {
        return AppState::detect($this->bridge->path('storage_path'));
    }

    /**
     * Мультисайт-fallback: сопоставляет хост запроса с темой view/app/.
     * Временная эвристика до появления таблицы sites: поддомен/домен вида
     * animals.localhost или animals.local -> тема 'animals'. Используется
     * только когда Theme-модуль недоступен.
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
}
