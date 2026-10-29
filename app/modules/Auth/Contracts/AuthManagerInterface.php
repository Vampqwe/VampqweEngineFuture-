<?php
declare(strict_types=1);

namespace App\modules\Auth\Contracts;

use App\Core\System\Http\Request;
use App\Core\System\Http\Response;

/**
 * Публичная граница HTTP-части модуля Auth (п.2 Конституции).
 *
 * Ядро (Kernel) НЕ знает деталей реализации: оно спрашивает наличие
 * контракта и делегирует маршруты /login, /register, /logout — ровно так же,
 * как это уже сделано для PageController модуля Pages (слабая связь, п.2).
 */
interface AuthManagerInterface
{
    /** GET /login — форма входа. */
    public function showLogin(Request $request): Response;

    /** POST /login — обработка входа (CSRF проверяет глобальный middleware). */
    public function login(Request $request): Response;

    /** GET /register — форма регистрации. */
    public function showRegister(Request $request): Response;

    /** POST /register — создание пользователя и автологин. */
    public function register(Request $request): Response;

    /** POST /logout — разрушение сессии. */
    public function logout(Request $request): Response;

    /** Есть ли аутентифицированный пользователь в текущей сессии. */
    public function check(): bool;

    /** Текущий пользователь (массив) или null. */
    public function user(): ?array;
}
