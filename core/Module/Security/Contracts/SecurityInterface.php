<?php
declare(strict_types=1);

namespace App\Core\Module\Security\Contracts;

/**
 * Контракт модуля Security — хеширование, токены, CSRF, шифрование.
 * Единственное, что видят другие модули (п.2 Конституции).
 */
interface SecurityInterface
{
    /** Надёжный случайный токен заданной длины (hex). */
    public function randomToken(int $bytes): string;

    /** Хешировать пароль (argon2id/bcrypt — по конфигу). */
    public function hashPassword(string $password): string;

    /** Проверить пароль по хешу. */
    public function verifyPassword(string $password, string $hash): bool;

    /** Сгенерировать CSRF-токен и привязать к сессии. */
    public function generateCsrfToken(): string;

    /** Валидировать присланный CSRF-токен. */
    public function validateCsrfToken(string $token): bool;

    /** Зашифровать значение симметричным ключом (app.key). */
    public function encrypt(string $plaintext): string;

    /** Расшифровать значение; null — payload повреждён/подделан или ключ не совпал. */
    public function decrypt(string $payload): ?string;
}
