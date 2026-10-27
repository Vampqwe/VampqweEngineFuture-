<?php
declare(strict_types=1);

namespace App\Core\Module\Session\Internal;

/**
 * Внутренняя криптография модуля Session: AES-256-GCM поверх app.key.
 * Формат: base64url(iv | tag | ciphertext). ЗАПРЕЩЕНО извне модуля (п.2).
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';

    public static function encrypt(string $plaintext, string $key): string
    {
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt($plaintext, self::CIPHER, self::aesKey($key), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ct === false) {
            throw new \RuntimeException('Не удалось зашифровать значение куки.');
        }

        return self::b64($iv . $tag . $ct);
    }

    public static function decrypt(string $payload, string $key): ?string
    {
        $raw = self::unb64($payload);
        if ($raw === null || strlen($raw) < 29) {
            return null;
        }
        $iv  = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ct  = substr($raw, 28);
        $pt  = openssl_decrypt($ct, self::CIPHER, self::aesKey($key), OPENSSL_RAW_DATA, $iv, $tag);

        return $pt === false ? null : $pt;
    }

    /** Ключ фиксированной длины 32 байта из произвольного app.key. */
    public static function aesKey(string $key): string
    {
        return hash('sha256', $key, true);
    }

    private static function b64(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private static function unb64(string $b64): ?string
    {
        $std = strtr($b64, '-_', '+/');
        $pad = strlen($std) % 4 === 0 ? '' : str_repeat('=', 4 - strlen($std) % 4);

        return base64_decode($std . $pad, true) ?: null;
    }

    private function __construct() {}
}
