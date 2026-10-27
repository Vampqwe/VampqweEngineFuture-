<?php
declare(strict_types=1);

namespace App\Core\Module\Security;

use App\Core\Module\Config\Contracts\ConfigRepositoryInterface;
use App\Core\Module\Security\Contracts\SecurityInterface;
use App\Core\Module\Security\Internal\Crypto;
use RuntimeException;

/**
 * Фасад модуля Security — реализован.
 *
 * Зависимости: Config (app.key для шифрования).
 * CSRF-токены живут в сессии (модуль Session) — здесь только генерация значения;
 * валидация по сессии доступна через SessionInterface::validateCsrf().
 */
final class Security implements SecurityInterface
{
    public function __construct(
        private readonly ConfigRepositoryInterface $config
    ) {
    }

    public function randomToken(int $bytes = 32): string
    {
        return bin2hex(random_bytes(max(1, $bytes)));
    }

    public function hashPassword(string $password): string
    {
        // argon2id — актуальный recommended алгоритм (PHP >= 7.3).
        return password_hash($password, PASSWORD_ARGON2ID);
    }

    public function verifyPassword(string $password, string $hash): bool
    {
        return $hash !== '' && password_verify($password, $hash);
    }

    /**
     * Генерация CSRF-токена (значение). Хранение и сверка — ответственность
     * Session::csrfToken()/validateCsrf() — токен живёт в сессии.
     */
    public function generateCsrfToken(): string
    {
        return $this->randomToken(32);
    }

    /**
     * Синхронная проверка «сам по себе» только для stateless-сценариев
     * (двойной submit-cookie). Для классического CSRF используйте
     * Session::validateCsrf($token) — сравнение с токеном в сессии.
     */
    public function validateCsrfToken(string $token): bool
    {
        return preg_match('/^[a-f0-9]{64}$/i', $token) === 1;
    }

    /** Зашифровать значение (AES-256-GCM, ключ app.key). */
    public function encrypt(string $plaintext): string
    {
        return Crypto::encrypt($plaintext, $this->appKey());
    }

    /** Расшифровать значение; null при повреждении/подделке payload. */
    public function decrypt(string $payload): ?string
    {
        return Crypto::decrypt($payload, $this->appKey());
    }

    /** Ключ приложения: app.key из конфига (или env), base64:-префикс декодируется. */
    private function appKey(): string
    {
        $key = (string) ($_ENV['APP_KEY'] ?? $this->config->get('app.key', ''));
        if ($key === '') {
            throw new RuntimeException('APP_KEY не задан — шифрование невозможно. Укажите его в .env.');
        }
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if ($decoded !== false && $decoded !== '') {
                $key = $decoded;
            }
        }

        return $key;
    }
}
