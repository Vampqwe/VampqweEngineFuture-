<?php
declare(strict_types=1);

namespace App\Core\Module\Session\Internal;

/**
 * Тонкий мост модуля Session к ЕДИНОМУ криптоузлу движка
 * (Security\Internal\Crypto, правило R10 Конституции).
 *
 * Исторически Session имел собственную копию AES-256-GCM с envelope
 * base64url(iv|tag|ct) — значения в этом формате уже лежат в куках
 * браузеров, поэтому ФОРМАТ сохранён, а примитивы переехали в Security.
 * Здесь только делегирование: openssl_* в этом файлу запрещён (R10).
 * ЗАПРЕЩЕНО извне модуля (п.2).
 */
final class Crypto
{
    public static function encrypt(string $plaintext, string $key): string
    {
        return \App\Core\Module\Security\Internal\Crypto::encryptUrl($plaintext, $key);
    }

    public static function decrypt(string $payload, string $key): ?string
    {
        return \App\Core\Module\Security\Internal\Crypto::decryptUrl($payload, $key);
    }

    private function __construct() {}
}
