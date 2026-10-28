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

        // П.4 Конституции: модули/темы регистрируют ассеты через хук-действие
        // без правки движка (после boot контейнер уже содержит Hook + Assets).
        if ($this->container->has(\App\Core\Module\Hook\Contracts\HookInterface::class)
            && $this->container->has(\App\Core\Module\Assets\Contracts\AssetsInterface::class)) {
            $this->container->get(\App\Core\Module\Hook\Contracts\HookInterface::class)
                ->dispatch('engine.assets.registered',
                    $this->container->get(\App\Core\Module\Assets\Contracts\AssetsInterface::class));
        }

        // Инсталлятор живёт на системной дороге и доступен при любом состоянии:
        // до установки это единственный способ довести движок до Running.
        if ($this->isInstallerPath($request->path)) {
            return $this->installerResponse($request);
        }

        // Статика (модуль Assets): /assets/<path> раздаётся PHP из каскада
        // site-sandbox -> theme -> core ДО проверки состояния — инсталлятору
        // и страницам ошибок свои CSS нужны и в NotInstalled/Maintenance.
        // До установки Theme/Database нет — контекст выставит сам assetResponse
        // best-effort (без него работает только CORE-каскад).
        if (str_starts_with($request->path, '/assets/')
            && $this->container->has(\App\Core\Module\Assets\Contracts\AssetsInterface::class)) {
            return $this->finish($this->assetResponse($request), $session);
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

        // Панель администратора (СИСТЕМНЫЙ модуль Admin, core/Module/Admin): ВСЯ дорога
        // /admin/* делегируется одному контракту AdminPanelInterface — ядро
        // не знает маршрутов/разделов/шаблонов панели (п.2). Регистрация ДО
        // /{slug}: '/admin' и любой хвост /admin/pages/edit/5 перехватываются
        // панелью раньше контентных страниц; Guard внутри facade проверяется
        // до маршрутизации по разделам.
        if ($this->container->has(\App\Core\Module\Admin\Contracts\AdminPanelInterface::class)) {
            /** @var \App\Core\Module\Admin\Contracts\AdminPanelInterface $admin */
            $admin = $this->container->get(\App\Core\Module\Admin\Contracts\AdminPanelInterface::class);
            $adminRoute = fn(Request $r): Response => $admin->dispatch($r);
            $userRouter->any('/admin', $adminRoute);
            $userRouter->any('/admin/{tail*}', $adminRoute);
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

    /**
     * Раздача статики (модуль Assets): /assets/<path> -> каскад
     * site-sandbox -> theme -> core. Контекст домена/темы выставляется здесь же
     * (best effort: без Theme-модуля/БД — только CORE-каскад). Отсутствующий
     * файл — 404 через модуль Error (п.9: не падать).
     */
    private function assetResponse(Request $request): Response
    {
        /** @var \App\Core\Module\Assets\Contracts\AssetsInterface $assets */
        $assets = $this->container->get(\App\Core\Module\Assets\Contracts\AssetsInterface::class);

        // Контекст запроса для каскада: тема сайта + домен из реестра (мультисайт).
        $theme  = '';
        $domain = null;
        if ($this->container->has(\App\Core\Module\Theme\Contracts\ThemeInterface::class)) {
            /** @var \App\Core\Module\Theme\Contracts\ThemeInterface $themes */
            $themes = $this->container->get(\App\Core\Module\Theme\Contracts\ThemeInterface::class);
            $host   = strtolower((string) ($request->header('host') ?? ''));
            $theme  = $themes->resolveForRequest($host);
            $site   = $themes->site();
            $domain = $site['domain'] ?? null;
        }
        $assets->useContext($domain, $theme !== '' ? $theme : \App\Core\Module\Theme\Theme::DEFAULT_THEME);

        $file = $assets->serve(substr($request->path, strlen('/assets/')));
        if ($file === null) {
            return $this->errorPage(404);
        }

        // Кэш-валидация: ETag по содержимому + Last-Modified по mtime файла.
        // Условный запрос (If-None-Match / If-Modified-Since) -> 304 без тела:
        // max-age 86400 + ревалидация = мгновенная раздача после изменения файла
        // (?v= в URL тоже ломает кэш, см. AssetsRegistry::url).
        $etag = '"' . md5($file['body']) . '"';
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
        // (реестр engine_sites -> эвристика поддомена -> дефолт из config/template.php).
        $theme = '';
        $site  = null;
        if ($this->container->has(\App\Core\Module\Theme\Contracts\ThemeInterface::class)) {
            /** @var \App\Core\Module\Theme\Contracts\ThemeInterface $themes */
            $themes = $this->container->get(\App\Core\Module\Theme\Contracts\ThemeInterface::class);
            $host   = $request->header('host') ?? '';
            $theme  = $themes->resolveForRequest($host);
            $site   = $themes->site();

            // Тема резолвлена, но её папки ещё нет (только что добавленный
            // сайт) — авто-клон стартера view/app/_starter. Движок не падает:
            // до клонирования рендер идёт через fallback '_default' в Template.
            if ($site !== null && $site['theme'] !== '' && !$themes->exists($site['theme'])
                && $this->container->has(\App\Core\Module\FileSystem\Contracts\FileSystemInterface::class)) {
                $fs      = $this->container->get(\App\Core\Module\FileSystem\Contracts\FileSystemInterface::class);
                $appView = (string) $this->container->get('app_view_path');
                $starter = $appView . '/_starter';
                $target  = $appView . '/' . $site['theme'];
                if ($fs->isDirectory($starter) && $this->cloneStarter($fs, $starter, $target)) {
                    $themes->use($site['theme']);
                    $theme = $site['theme'];
                }
            }

            // Хук мультисайта: сторонние модули реагируют на резолв сайта
            // без правки ядра (п.4 Конституции).
            if ($theme !== '' && $this->container->has(HookInterface::class)) {
                /** @var HookInterface $hooks */
                $hooks = $this->container->get(HookInterface::class);
                $hooks->dispatch('theme.resolved', $theme, $site, $host);
            }
        } elseif ($this->container->has(\App\Core\Module\Template\Contracts\TemplateInterface::class)) {
            // Fallback без Theme-модуля: прежняя эвристика поддомена.
            $theme = $this->resolveThemeByHost($request->header('host') ?? '');
        }

        // Рендер через модуль Template (view/app/<theme>/home.phtml).
        if ($this->container->has(\App\Core\Module\Template\Contracts\TemplateInterface::class)) {
            /** @var \App\Core\Module\Template\Contracts\TemplateInterface $views */
            $views = $this->container->get(\App\Core\Module\Template\Contracts\TemplateInterface::class);

            // Статика темы — через реестр Assets (каскад site->theme->core):
            // регистрируем 'site.css'/'site.js' и публикуем теги в шаблоны.
            // Файлы темы меняются без правки layout; хук engine.assets.*
            // даёт модулям дописать своё (п.4). Если файлов нет — в касскаде
            // совпадения не будет, URL всё равно корректен (404 без ?v=),
            // а layout темы использует инлайн-fallback.
            if ($this->container->has(\App\Core\Module\Assets\Contracts\AssetsInterface::class)) {
                /** @var \App\Core\Module\Assets\Contracts\AssetsInterface $assets */
                $assets = $this->container->get(\App\Core\Module\Assets\Contracts\AssetsInterface::class);
                $assets->useContext($site['domain'] ?? null, $theme !== '' ? $theme : \App\Core\Module\Theme\Theme::DEFAULT_THEME);
                $assets->registerStyle('site', 'css/site.css');
                $assets->registerScript('site', 'js/site.js');
                $views->share('assetsStyles', $assets->styles());
                $views->share('assetsScripts', $assets->scripts());
            }

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
                    'site'       => $site,
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
     * Мультисайт: рекурсивно клонирует папку-стартер темы (view/app/_starter)
     * в новую тему сайта. best effort: любой сбой FS означает «папки нет»,
     * рендер уйдёт через fallback '_default' — движок не падает (принцип п.9).
     */
    private function cloneStarter(
        \App\Core\Module\FileSystem\Contracts\FileSystemInterface $fs,
        string $source,
        string $target,
    ): bool {
        if (!$fs->createDirectory($target)) {
            return false;
        }

        foreach ((array) glob($source . '/*') as $entry) {
            $name = $fs->basename((string) $entry);
            if ($name === '' || $name[0] === '.') {
                continue;
            }
            $dest = $target . '/' . $name;
            if (is_dir($entry)) {
                if (!$this->cloneStarter($fs, (string) $entry, $dest)) {
                    return false;
                }
            } elseif (!$fs->copy((string) $entry, $dest)) {
                return false;
            }
        }

        return true;
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
