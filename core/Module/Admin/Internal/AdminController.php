<?php
declare(strict_types=1);

namespace App\Core\Module\Admin\Internal;

use App\Core\Module\Hook\Contracts\HookInterface;
use App\Core\System\Container\Contracts\ContainerInterface;
use App\Core\System\Http\Request;
use App\Core\System\Http\Response;
use App\Core\Module\Admin\Contracts\AdminPanelInterface;
use App\Core\Module\Admin\Contracts\AdminSectionInterface;
use App\Core\Module\Admin\Contracts\GuardInterface;
use RuntimeException;

/**
 * HTTP-фасад панели (внутренняя кухня, п.2 Конституции).
 *
 * Единая точка входа для ВСЕЙ дороги /admin/*: Kernel делегирует сюда один
 * маршрут `/admin/{path}`. Дальше панель сама:
 *   1) спрашивает GuardInterface (доступ до маршрутизации — обойти нельзя);
 *   2) собирает разделы из DI-контейнера (все сервисы, реализующие
 *      AdminSectionInterface) — меню и маршруты строятся ИЗ разделов,
 *      а не прописываются в коде панели (п.2/п.4: новый модуль добавляет
 *      раздел нулём строк здесь);
 *   3) маршрутизирует segment'ы пути внутри раздела;
 *   4) прогоняет ответ через фильтр 'admin.response' (хук-шина, п.4).
 *
 * Разрешение зависимостей — только через контракты (п.3), лениво: без БД
 * или шаблонов панель деградирует честно, а не падает («движок не падает»).
 */
final class AdminController implements AdminPanelInterface
{
    /** @var array<string,AdminSectionInterface>|null кэш собранных разделов */
    private ?array $sections = null;

    /** @var list<string> FQN разделов, объявленные провайдерами (см. registerSections()). */
    private array $declaredSections = [];

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ?GuardInterface $guard = null,
        private readonly ?HookInterface $hooks = null,
    ) {
    }

    /**
     * Регистрация раздела панели (публичный API фасада, п.2/п.4).
     * Вызывается из AdminServiceProvider для своих разделов ИЛИ из любого
     * стороннего модуля через хук-шину:
     *   $hooks->addAction('admin.sections', fn() => $admin->registerSections([MySection::class]));
     * Панель при сборке меню/маршрутов спросит контейнер по каждому FQN —
     * сама реализация живёт в чужом модуле, панель её не знает.
     */
    public function registerSections(array $fqcnList): void
    {
        foreach ($fqcnList as $fqcn) {
            if (is_string($fqcn)) {
                $this->declaredSections[] = $fqcn;
            }
        }

        // Меню и маршруты пересоберутся при следующем запросе.
        $this->sections = null;
    }

    /**
     * Разбор пути: /admin, /admin/<section>, /admin/<section>/<action...>
     * (Kernel матчит '/admin' без слэша — сегменты могут быть пустыми).
     */
    private function parsePath(Request $request): array
    {
        $segments = array_values(array_filter(explode('/', ltrim($request->path, '/')), static fn(string $s): bool => $s !== ''));
        array_shift($segments); // 'admin'
        $slug = strtolower(array_shift($segments) ?? '');
        $sub  = implode('/', $segments);

        return [$slug, $sub];
    }

    public function dispatch(Request $request): Response
    {
        // 0) POST /admin/login — форма входа панели (до стража; CSRF уже
        //    проверен глобальным middleware ядра на этой дороге, п.4).
        [$slug, $sub] = $this->parsePath($request);
        if ($slug === 'login') {
            return $request->method === 'POST' ? $this->handleLogin($request) : $this->loginForm($request);
        }

        // 0.5) GET /admin/logout — выход из панели: снимаем метки допуска,
        //      саму сессию не трогаем (пользовательский вход Auth остаётся).
        if ($slug === 'logout') {
            $session = $this->container->has(\App\Core\Module\Session\Contracts\SessionInterface::class)
                ? $this->container->get(\App\Core\Module\Session\Contracts\SessionInterface::class)
                : null;
            if ($session !== null) {
                $session->start();
                $session->forget('super_admin_verified');
                $session->forget('admin_verified_at');
            }
            $this->hooks?->dispatch('admin.logout', $request);

            return new Response('', 302, ['Location' => '/admin/login']);
        }

        // 1) Страж — ДО любой маршрутизации по разделам.
        if ($this->guard !== null && ($blocked = $this->guard->guard($request)) instanceof Response) {
            return $blocked;
        }

        // 2) Пустой путь — дашборд; неизвестный раздел — 404 панели.
        $sections = $this->sections();

        // Контекст шаблонов панели (SCOPE_CORE, view/core/admin/): меню
        // строится ИЗ реестра разделов (п.2), активный пункт — по пути
        // запроса; токен — для форм (CSRF проверяет глобальный middleware ядра).
        // Статика панели — через реестр Assets (SCOPE_CORE) и хуки
        // engine.assets.styles/scripts: layout не хардкодит теги, сторонний
        // модуль может добавить свой CSS панели без правки ядра (п.4).
        if ($this->container->has(\App\Core\Module\Assets\Contracts\AssetsInterface::class)) {
            /** @var \App\Core\Module\Assets\Contracts\AssetsInterface $assets */
            $assets = $this->container->get(\App\Core\Module\Assets\Contracts\AssetsInterface::class);
            $assets->registerStyle('admin', 'css/admin.css', [], \App\Core\Module\Assets\Contracts\AssetsInterface::SCOPE_CORE);
            $assets->registerScript('admin', 'js/admin.js', [], \App\Core\Module\Assets\Contracts\AssetsInterface::SCOPE_CORE);

            if ($this->container->has(\App\Core\Module\Template\Contracts\TemplateInterface::class)) {
                $tpl0 = $this->container->get(\App\Core\Module\Template\Contracts\TemplateInterface::class);
                assert($tpl0 instanceof \App\Core\Module\Template\Contracts\TemplateInterface);
                $tpl0->share('assetsStyles', $assets->styles());
                $tpl0->share('assetsScripts', $assets->scripts());
            }
        }

        if ($this->container->has(\App\Core\Module\Template\Contracts\TemplateInterface::class)) {
            $tpl = $this->container->get(\App\Core\Module\Template\Contracts\TemplateInterface::class);
            assert($tpl instanceof \App\Core\Module\Template\Contracts\TemplateInterface);
            $tpl->share('adminNav', array_map(
                static fn(AdminSectionInterface $s): array => [
                    'href'  => $s->slug() === '' ? '/admin' : '/admin/' . $s->slug(),
                    'title' => $s->title(),
                    'icon'  => $s->icon(),
                ],
                array_values($sections)
            ));
            $tpl->share('adminPath', '/' . trim($request->path, '/'));
            $session = $this->container->has(\App\Core\Module\Session\Contracts\SessionInterface::class)
                ? $this->container->get(\App\Core\Module\Session\Contracts\SessionInterface::class)
                : null;
            $tpl->share('csrf', $session !== null ? $session->csrfToken() : '');
        }

        if ($slug === '') {
            $response = $this->dashboard($request, $sections);
        } elseif (($section = $sections[$slug] ?? null) !== null) {
            $response = $sub === ''
                ? $section->index($request)
                : ($section->handle($sub, $request) ?? $this->notFound());
        } else {
            $response = $this->notFound();
        }

        // 4) Пост-фильтр ответа (расширение без правки панели, п.4).
        if ($this->hooks !== null) {
            $filtered = $this->hooks->applyFilters('admin.response', $response, $request, $this->container);
            if ($filtered instanceof Response) {
                $response = $filtered;
            }
        }

        return $response;
    }

    /**
     * Сборка разделов: сканируем манифест-реестр контейнера на наличие
     * зарегистрированных сервисов-разделов. Каждый раздел регистрирует СВОЙ
     * FQN в провайдере; панель знает только контракт AdminSectionInterface.
     *
     * @return array<string,AdminSectionInterface> slug => раздел
     */
    private function sections(): array
    {
        if ($this->sections !== null) {
            return $this->sections;
        }

        // Диспатч действия: сторонние модули регистрируют здесь свои разделы.
        if ($this->hooks !== null && $this->hooks->has('admin.sections')) {
            $this->hooks->dispatch('admin.sections', $this, $this->container);
        }

        $found = [];
        foreach (array_unique($this->declaredSections) as $fqcn) {
            if (!$this->container->has($fqcn)) {
                continue;
            }
            $service = $this->container->get($fqcn);
            if ($service instanceof AdminSectionInterface) {
                $found[$service->slug()] = $service;
            }
        }

        uasort($found, static fn(AdminSectionInterface $a, AdminSectionInterface $b): int
            => $a->order() <=> $b->order());

        return $this->sections = $found;
    }

    /** Дашборд /admin — сводка по всем разделам сразу. */
    private function dashboard(Request $request, array $sections): Response
    {
        $dash = $sections[''] ?? null;
        if ($dash instanceof AdminSectionInterface) {
            return $dash->index($request);
        }

        throw new RuntimeException('Раздел дашборда не зарегистрирован.');
    }

    /**
     * Форма входа панели (GET /admin/login).
     *
     * ВАЖНО (защита от 419): токен генерится ДО рендера и ЯВНО передаётся в
     * данные шаблона. Layout панели может брать $csrf из разделённых переменных
     * (share), но сам шаблон login.phtml не должен полагаться на то, что
     * csrfToken() вызовется второй раз «в нужном порядке» — это хрупко.
     */
    private function loginForm(Request $request, string $error = ''): Response
    {
        $session = $this->container->has(\App\Core\Module\Session\Contracts\SessionInterface::class)
            ? $this->container->get(\App\Core\Module\Session\Contracts\SessionInterface::class)
            : null;
        $token = $session !== null ? $session->csrfToken() : '';

        $tpl = $this->container->has(\App\Core\Module\Template\Contracts\TemplateInterface::class)
            ? $this->container->get(\App\Core\Module\Template\Contracts\TemplateInterface::class)
            : null;

        // SCOPE_CORE: панель — системная дорога (п.3), её шаблоны живут в
        // view/core/admin/ и НЕ зависят от темы сайта (view/app/ — витрина).
        $html = $tpl?->renderCore('admin/login', [
            'title' => 'Вход в панель',
            'error' => $error,
            'next'  => (string) ($request->get('next') ?? '/admin'),
            'csrf'  => $token,
        ]) ?? $this->inlineLogin($error, (string) ($request->get('next') ?? '/admin'));

        return new Response($html);
    }

    /** POST /admin/login — проверка SuperAdmin (.env, п.8) или users-роли. */
    private function handleLogin(Request $request): Response
    {
        $login    = trim((string) ($request->post['login'] ?? ''));
        $password = (string) ($request->post['password'] ?? '');
        $next     = (string) ($request->post['next'] ?? '/admin');
        $session  = $this->container->has(\App\Core\Module\Session\Contracts\SessionInterface::class)
            ? $this->container->get(\App\Core\Module\Session\Contracts\SessionInterface::class)
            : null;

        if ($session === null || $login === '' || $password === '') {
            return $this->loginForm($request, 'Заполните логин и пароль.');
        }

        // 1) SuperAdmin ядра: имя из Support\SuperAdmin, argon2id-хеш из .env (п.8).
        if (\App\Core\System\Support\SuperAdmin::isName($login)
            && \App\Core\System\Support\SuperAdmin::hasConfiguredPassword()
            && $this->container->has(\App\Core\Module\Security\Contracts\SecurityInterface::class)
        ) {
            $security = $this->container->get(\App\Core\Module\Security\Contracts\SecurityInterface::class);
            assert($security instanceof \App\Core\Module\Security\Contracts\SecurityInterface);
            if ($security->verifyPassword($password, (string) \App\Core\System\Support\SuperAdmin::expectedHash())) {
                // Антификсация при повышении привилегий — через startPreservingCsrf():
                // новый id, но CSRF-токен переносится в новую запись до удаления старой.
                // Голый start() здесь терял токен и приводил к 419 на следующем POST.
                $session->startPreservingCsrf();
                $session->set('super_admin_verified', true);
                $session->set('admin_verified_at', ['at' => time(), 'uid' => 0]);
                $this->hooks?->dispatch('admin.login', 0, $login);

                return new Response('', 302, ['Location' => $next !== '' ? $next : '/admin']);
            }

            return $this->loginForm($request, 'Неверный логин или пароль.');
        }

        // 2) Пользователь с ролью админа: email+пароль через контракт Auth,
        //    роль — через таблицу users (единый источник истины, п.7).
        if ($this->container->has(\App\modules\Auth\Contracts\AuthRepositoryInterface::class)) {
            $users = $this->container->get(\App\modules\Auth\Contracts\AuthRepositoryInterface::class);
            assert($users instanceof \App\modules\Auth\Contracts\AuthRepositoryInterface);
            $user = $users->findByEmail($login);
            if ($user !== null
                && $users->verifyPassword($user, $password)
                && in_array((string) ($user['role'] ?? ''), AdminGuard::ADMIN_ROLES, true)
            ) {
                // Антификсация при повышении привилегий — startPreservingCsrf()
                // (голый start() терял CSRF-токен -> 419 на следующем POST).
                $session->startPreservingCsrf();
                $session->set('auth_user_id', (int) $user['id']);
                $session->set('admin_verified_at', ['at' => time(), 'uid' => (int) $user['id']]);
                $this->hooks?->dispatch('admin.login', (int) $user['id'], $login);

                return new Response('', 302, ['Location' => $next !== '' ? $next : '/admin']);
            }
        }

        // Единое сообщение: не подсвечиваем, существует ли такой логин.
        return $this->loginForm($request, 'Неверный логин или пароль.');
    }

    /** Минимальный инлайн-HTML формы входа, если Template недоступен. */
    private function inlineLogin(string $error, string $next): string
    {
        $token = '';
        if ($this->container->has(\App\Core\Module\Session\Contracts\SessionInterface::class)) {
            $token = htmlspecialchars(
                $this->container->get(\App\Core\Module\Session\Contracts\SessionInterface::class)->csrfToken(),
                ENT_QUOTES
            );
        }

        return '<!doctype html><meta charset="utf-8"><title>Вход в панель</title>'
            . ($error !== '' ? '<p style="color:#c00">' . htmlspecialchars($error, ENT_QUOTES) . '</p>' : '')
            . '<form method="post" action="/admin/login">'
            . '<input type="hidden" name="_csrf" value="' . $token . '">'
            . '<input type="hidden" name="next" value="' . htmlspecialchars($next, ENT_QUOTES) . '">'
            . '<p><label>Логин <input name="login" required></label></p>'
            . '<p><label>Пароль <input type="password" name="password" required></label></p>'
            . '<p><button type="submit">Войти</button></p></form>';
    }

    private function notFound(): Response
    {
        return new Response('<h1>404</h1><p>Раздела панели не существует.</p>', 404);
    }
}
