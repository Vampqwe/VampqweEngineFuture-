<?php
declare(strict_types=1);

namespace App\Core\Module\Security;

use App\Core\Module\Security\Contracts\SecurityInterface;
use RuntimeException;

/**
 * Фасад модуля Security.
 *
 * Зависимости: Config (app.key, алгоритмы), TimeDate (протухание токенов).
 * РЕАЛИЗАЦИЯ В ПЛАНЕ. Исключение: randomToken уже безопасен и работает.
 */
final class Security implements SecurityInterface
{
    public function randomToken(int $bytes = 32): string
    {
        return bin2hex(random_bytes(max(1, $bytes)));
    }

    public function hashPassword(string $password): string
    {
        throw new RuntimeException('Модуль [Security] объявлен в ядре, но ещё не реализован.');
    }

    public function verifyPassword(string $password, string $hash): bool
    {
        throw new RuntimeException('Модуль [Security] ещё не реализован.');
    }

    public function generateCsrfToken(): string
    {
        throw new RuntimeException('Модуль [Security] ещё не реализован.');
    }

    public function validateCsrfToken(string $token): bool
    {
        throw new RuntimeException('Модуль [Security] ещё не реализован.');
    }

    public function encrypt(string $plaintext): string
    {
        throw new RuntimeException('Модуль [Security] ещё не реализован.');
    }

    public function decrypt(string $payload): string
    {
        throw new RuntimeException('Модуль [Security] ещё не реализован.');
    }
}
