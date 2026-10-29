<?php
declare(strict_types=1);

namespace App\Core\Module\Admin\Internal;

use App\Core\Module\Security\Contracts\SecurityInterface;
use App\Core\Module\Session\Contracts\SessionInterface;
use App\Core\System\Http\Request;
use App\Core\System\Http\Response;
use App\Core\System\Support\SuperAdmin;
use App\Core\Module\Admin\Contracts\GuardInterface;
use App\modules\Auth\Contracts\AuthRepositoryInterface;

/**
 * Страж панели (внутренняя кухня, п.2 Конституции).
 *
 * Правила допуска — единый источник истины:
 *  - SuperAdmin (п.8: имя из ядра, argon2id-хеш из .env) допущен всегда;
 *  - пользователь из таблицы users с ролью 'admin' или 'super_administrator'
 *    допущен, если его id закреплён в серверной сессии ключом auth_user_id
 *    (тот же ключ, что у модуля Auth — никакого второго механизма входа);
 *  - прочие — редирект на страницу входа панели /admin/login (гостям)
 *    или честная 403 (залогиненному без роли).
 *
 * Проверка выполняется ПЕРЕД маршрутизацией по разделам — обойти её
 * маршрутом невозможно по построению.
 */
final class AdminGuard implements GuardInterface
{
    /** Роли пользователей-администраторов (ср. единственная роль SuperAdmin::ROLE). */
    public const ADMIN_ROLES = ['admin', 'super_administrator'];

    /** Ключ входа в сессию панели: время проверки + id вошедшего (антификсация). */
    private const SESSION_KEY = 'admin_verified_at';

    private const GRACE_SECONDS = 1800; // получасовой «покой» после проверки пароля

    public function __construct(
        private readonly ?SessionInterface $session = null,
        private readonly ?SecurityInterface $security = null,
        private readonly ?AuthRepositoryInterface $users = null,
    ) {
    }

    public function guard(Request $request): ?Response
    {
        if ($this->session === null) {
            return new Response('<h1>503</h1><p>Сессии недоступны — панель не может проверить вход.</p>', 503);
        }

        // 1) Быстрый путь: уже подтверждённый вход в этой сессии.
        $stamp = $this->session->get(self::SESSION_KEY);
        if (is_array($stamp)
            && (time() - (int) ($stamp['at'] ?? 0)) < self::GRACE_SECONDS
            && $this->stillAllowed((int) ($stamp['uid'] ?? 0))
        ) {
            return null; // пропускаем
        }

        // 2) SuperAdmin без БД: заложен в ядро (п.8), пароль — из .env.
        //    ВАЖНО: просто «хеш задан» ≠ «мы вошли» — доступ разрешается
        //    только после успешной проверки пароля на /admin/login
        //    (см. AdminController::handleLogin, там ставится admin_verified_at).
        if (SuperAdmin::hasConfiguredPassword() && $this->session->get('super_admin_verified') === true) {
            return null;
        }

        // 3) Пользователь с ролью админа: сверяем id из сессии Auth и роль из БД.
        $userId = (int) ($this->session->get('auth_user_id') ?? 0);
        if ($userId > 0 && $this->userIsAdmin($userId)) {
            $this->session->set(self::SESSION_KEY, ['at' => time(), 'uid' => $userId]);
            return null;
        }

        // 4) Гость — на страницу входа; залогиненный без роли — 403.
        if ($userId > 0) {
            return new Response(
                '<h1>403</h1><p>У вашей учётной записи нет доступа к панели.</p>',
                403,
                ['Content-Type' => 'text/html; charset=utf-8'],
            );
        }

        // Гостя — на СТРАНИЦУ ВХОДА ПАНЕЛИ (/admin/login), а не на пользовательский
        // /login: SuperAdmin существует только в ядре (п.8) и таблицы users,
        // поэтому через Auth-форму он войти физически не сможет. Форма панели
        // сама принимает оба типа учётных записей (AdminController::handleLogin).
        return new Response('', 302, ['Location' => '/admin/login']);
    }

    /** Подтверждён ли доступ сейчас (переиспользуется контроллером при каждом запросе). */
    public function stillAllowed(int $uid): bool
    {
        if ($uid === 0) {
            return SuperAdmin::hasConfiguredPassword();
        }

        return $uid > 0 && $this->userIsAdmin($uid);
    }

    /** Есть ли у пользователя из БД роль администратора. */
    private function userIsAdmin(int $id): bool
    {
        if ($this->users === null) {
            return false;
        }

        $user = $this->users->findById($id);

        return $user !== null
            && in_array((string) ($user['role'] ?? ''), self::ADMIN_ROLES, true);
    }
}
