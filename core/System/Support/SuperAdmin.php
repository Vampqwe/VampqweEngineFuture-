<?php
declare(strict_types=1);

namespace App\Core\System\Support;

/**
 * ════════════════════════════════════════════════════════════════════════
 *  СУПЕР-АДМИНИСТРАТОР ДВИЖКА — ЕДИНЫЙ И НЕПРЕХОДИМЫЙ
 * ════════════════════════════════════════════════════════════════════════
 *
 * Доверенный управленец зашит в ядро. Это осознанный единственный хардкод
 * учётной записи (аналог root в Unix): ни один модуль, база или конфигурация
 * его не описывают и не могут отменить.
 *
 * Инварианты:
 *  - экзепляр один — роль «супер-администратор» универсальна и больше нигде
 *    не упоминается и не присваивается;
 *  - не хранится в БД/файлах — проверяется по имени пользователя;
 *  - пароль задаётся на установке (Installer), хранится в .env как SUPER_ADMIN_HASH
 *    (argon2id) и проверяется через Security::verifyPassword();
 *  - любой вход с именем SuperAdmin требует успешной проверки хеша —
 *    даже если в будущем в БД появится пользователь с таким же логином.
 */
final class SuperAdmin
{
    /** Единственное имя доверенного управленца. Не меняется ни при каких условиях. */
    public const USERNAME = 'SuperAdmin';

    /** Универсальная единственная роль. Больше нигде не определяется и не используется. */
    public const ROLE = 'super_administrator';

    /** Ключ .env, куда Installer пишет argon2id-хеш пароля супер-администратора. */
    public const PASSWORD_HASH_ENV = 'SUPER_ADMIN_HASH';

    private function __construct()
    {
        // Запрет инстанцирования: класс — константа ядра, а не сервис.
    }

    public static function username(): string
    {
        return self::USERNAME;
    }

    public static function role(): string
    {
        return self::ROLE;
    }

    /** Совпадает ли введённое имя с супер-администратором (без учёта регистра). */
    public static function isName(string $username): bool
    {
        return strcasecmp(trim($username), self::USERNAME) === 0;
    }

    /**
     * Есть ли у движка заданный пароль супер-администратора
     * (.env создан Installer'ом и содержит непустой хеш).
     */
    public static function hasConfiguredPassword(): bool
    {
        $hash = $_ENV[self::PASSWORD_HASH_ENV] ?? getenv(self::PASSWORD_HASH_ENV);

        return is_string($hash) && $hash !== '';
    }

    /** Ожиданный argon2id-хеш пароля из .env (или null, если ещё не задан). */
    public static function expectedHash(): ?string
    {
        $hash = $_ENV[self::PASSWORD_HASH_ENV] ?? getenv(self::PASSWORD_HASH_ENV);

        return (is_string($hash) && $hash !== '') ? $hash : null;
    }
}
