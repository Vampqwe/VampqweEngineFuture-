<?php
declare(strict_types=1);

namespace App\modules\Auth;

use App\Core\Module\Hook\Contracts\HookInterface;
use App\Core\Module\Session\Contracts\SessionInterface;
use App\Core\Module\Template\Contracts\TemplateInterface;
use App\modules\Auth\Contracts\AuthManagerInterface;
use App\modules\Auth\Contracts\AuthRepositoryInterface;
use App\Core\System\Http\Request;
use App\Core\System\Http\Response;

/**
 * HTTP-контроллер модуля Auth (внутренняя кухня, п.2 Конституции).
 *
 * Вход/регистрация/выход поверх Session-модуля (серверные сессии +
 * подписанная кука). Хук-шина (п.4): после успешного входа диспатчится
 * действие 'auth.login', выхода — 'auth.logout'; перед ответом регистрации
 * — фильтр 'auth.register_allowed' (будущие капчи/лимиты без правки ядра).
 *
 * Честная деградация («движок не падает»): без Database/Auth — 503-заглушка
 * со словами «модуль недоступен», без Template — минимальный инлайн-HTML.
 */
final class AuthController implements AuthManagerInterface
{
    /** Ключ пользователя в серверной сессии. */
    private const SESSION_KEY = 'auth_user_id';

    public function __construct(
        private readonly ?AuthRepositoryInterface $users,
        private readonly ?SessionInterface $session,
        private readonly ?TemplateInterface $views,
        private readonly ?HookInterface $hooks = null,
    ) {
    }

    public function showLogin(Request $request): Response
    {
        return $this->page('auth/login', ['title' => 'Вход'], 'login');
    }

    public function login(Request $request): Response
    {
        if (!$this->ready()) {
            return $this->unavailable();
        }

        $email    = (string) ($request->get('email') ?? '');
        $password = (string) ($request->get('password') ?? '');

        $user = $this->users?->findByEmail($email);
        if ($user === null || !$this->users->verifyPassword($user, $password)) {
            // Единое сообщение: не подсвечиваем, существует ли такой email.
            return $this->page('auth/login', [
                'title' => 'Вход',
                'error' => 'Неверный email или пароль',
            ], 'login', 401);
        }

        // Защита от session fixation: новый id при повышении привилегий.
        $this->session?->start();
        $this->session?->set(self::SESSION_KEY, (int) $user['id']);

        // Rehash «на лету», если PHP предложил более сильный алгоритм.
        $hash = (string) $user['password'];
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $this->users->updatePassword((int) $user['id'], password_hash($password, PASSWORD_DEFAULT));
        }

        $this->dispatch('auth.login', $user);

        return $this->redirect('/');
    }

    public function showRegister(Request $request): Response
    {
        return $this->page('auth/register', ['title' => 'Регистрация'], 'register');
    }

    public function register(Request $request): Response
    {
        if (!$this->ready()) {
            return $this->unavailable();
        }

        // Фильтр хук-шины: сторонний модуль может запретить регистрацию
        // (капча, оверрайды конфига) без правки этого кода (п.4 Конституции).
        if ($this->hooks !== null && !$this->hooks->applyFilters('auth.register_allowed', true, $request)) {
            return $this->page('auth/register', [
                'title' => 'Регистрация',
                'error' => 'Регистрация временно закрыта',
            ], 'register', 403);
        }

        $email    = (string) ($request->get('email') ?? '');
        $password = (string) ($request->get('password') ?? '');
        $name     = (string) ($request->get('name') ?? '');

        $id = $this->users?->create($email, $password, $name);
        if ($id === null) {
            return $this->page('auth/register', [
                'title' => 'Регистрация',
                'error' => 'Email занят или данные некорректны (пароль от 8 символов)',
            ], 'register', 422);
        }

        $this->session?->start();
        $this->session?->set(self::SESSION_KEY, $id);

        $this->dispatch('auth.registered', ['id' => $id, 'email' => strtolower($email)]);

        return $this->redirect('/');
    }

    public function logout(Request $request): Response
    {
        $user = $this->user();
        $this->session?->destroy();
        if ($user !== null) {
            $this->dispatch('auth.logout', $user);
        }

        return $this->redirect('/');
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function user(): ?array
    {
        if ($this->users === null || $this->session === null) {
            return null;
        }

        $id = $this->session->get(self::SESSION_KEY);
        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
            return null;
        }

        return $this->users->findById((int) $id);
    }

    private function ready(): bool
    {
        return $this->users !== null && $this->session !== null;
    }

    /** Рендер через тему (SCOPE_APP) с fallback на инлайн-минималку. */
    private function page(string $template, array $data, string $fallbackName, int $status = 200): Response
    {
        if ($this->views !== null && $this->views->exists($template)) {
            try {
                return new Response($this->views->render($template, $data + ['csrf' => $this->session?->csrfToken()]), $status,
                    ['Content-Type' => 'text/html; charset=utf-8']);
            } catch (\Throwable) {
                // падаем в fallback — страница важнее темы
            }
        }

        return new Response($this->fallbackHtml($fallbackName, $data), $status,
            ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** Модуль деградировал (нет БД/сессий до установки) — честная 503. */
    private function unavailable(): Response
    {
        return new Response(
            '<!doctype html><meta charset="utf-8"><h1>Авторизация недоступна</h1>'
            . '<p>Модуль Auth не готов: отсутствуют зависимости (Database/Session).</p>',
            503,
            ['Content-Type' => 'text/html; charset=utf-8']
        );
    }

    private function fallbackHtml(string $which, array $data): string
    {
        $e   = htmlspecialchars((string) ($data['error'] ?? ''), ENT_QUOTES);
        $t   = htmlspecialchars((string) ($data['title'] ?? ''), ENT_QUOTES);
        $tok = htmlspecialchars((string) ($this->session?->csrfToken() ?? ''), ENT_QUOTES);

        $fields = $which === 'register'
            ? '<input name="name" placeholder="Имя"><input name="email" type="email" required>'
              . '<input name="password" type="password" required minlength="8">'
            : '<input name="email" type="email" required><input name="password" type="password" required>';

        return "<!doctype html><meta charset=\"utf-8\"><title>{$t}</title>"
             . "<h1>{$t}</h1>{$e}<form method=\"post\">"
             . "<input type=\"hidden\" name=\"_token\" value=\"{$tok}\">{$fields}"
             . '<button type="submit">ОК</button></form>';
    }

    private function dispatch(string $hook, mixed $payload): void
    {
        try {
            $this->hooks?->dispatch($hook, $payload);
        } catch (\Throwable) {
            // изоляция ошибок слушателей (принцип отказоустойчивости)
        }
    }

    private function redirect(string $to): Response
    {
        return new Response('', 302, ['Location' => $to]);
    }
}
