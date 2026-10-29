<?php
declare(strict_types=1);

namespace App\Core\Module\Security\Internal;

/**
 * Внутренняя криптография модуля Security: AES-256-GCM поверх app.key.
 * Формат payload: base64( "gcm1" | iv(12) | tag(16) | ciphertext ).
 * ЗАПРЕЩЕНО использовать напрямую извне модуля (п.2 Конституции).
 */
final class Crypto
{
    private const CIPHER  = 'aes-256-gcm';
    private const MAGIC   = 'gcm1';
    private const IV_LEN  = 12;
    private const TAG_LEN = 16;

    public static function encrypt(string $plaintext, string $appKey): string
    {
        $iv  = random_bytes(self::IV_LEN);
        $tag = '';
        $ct  = openssl_encrypt(
            $plaintext, self::CIPHER, self::aesKey($appKey),
            OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LEN
        );
        if ($ct === false) {
            throw new \RuntimeException('Шифрование не удалось (openssl).');
        }

        return base64_encode(self::MAGIC . $iv . $tag . $ct);
    }

    /** @return string|null null — повреждён/подделан payload или неверный ключ */
    public static function decrypt(string $payload, string $appKey): ?string
    {
        $raw = base64_decode($payload, true);
        if ($raw === false || !str_starts_with($raw, self::MAGIC)) {
            return null;
        }
        $raw = substr($raw, strlen(self::MAGIC));
        if (strlen($raw) < self::IV_LEN + self::TAG_LEN) {
            return null;
        }
        $iv = substr($raw, 0, self::IV_LEN);
        $tag = substr($raw, self::IV_LEN, self::TAG_LEN);
        $ct  = substr($raw, self::IV_LEN + self::TAG_LEN);
        $pt  = openssl_decrypt($ct, self::CIPHER, self::aesKey($appKey), OPENSSL_RAW_DATA, $iv, $tag);

        return $pt === false ? null : $pt;
    }

    /** Ключ фиксированной длины (32 байта) из произвольного app.key. */
    public static function aesKey(string $appKey): string
    {
        return hash('sha256', $appKey, true);
    }

    /**
     * Cookie-safe вариант envelope: base64url(iv | tag | ciphertext) без MAGIC.
     * Формат исторический — принадлежит модулю Session (значения уже лежат в
     * куках браузеров), но криптопримитив здесь один (R10 Конституции).
     */
    public static function encryptUrl(string $plaintext, string $appKey): string
    {
        $iv  = random_bytes(self::IV_LEN);
        $tag = '';
        $ct  = openssl_encrypt(
            $plaintext, self::CIPHER, self::aesKey($appKey),
            OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LEN
        );
        if ($ct === false) {
            throw new \RuntimeException('Шифрование не удалось (openssl).');
        }

        return rtrim(strtr(base64_encode($iv . $tag . $ct), '+/', '-_'), '=');
    }

    /** Расшифровать payload формата encryptUrl(); null — битый/подделанный данные. */
    public static function decryptUrl(string $payload, string $appKey): ?string
    {
        $std = strtr($payload, '-_', '+/');
        $pad = strlen($std) % 4 === 0 ? '' : str_repeat('=', 4 - strlen($std) % 4);
        $raw = base64_decode($std . $pad, true);
        if ($raw === false || strlen($raw) < self::IV_LEN + self::TAG_LEN + 1) {
            return null;
        }
        $iv  = substr($raw, 0, self::IV_LEN);
        $tag = substr($raw, self::IV_LEN, self::TAG_LEN);
        $ct  = substr($raw, self::IV_LEN + self::TAG_LEN);
        $pt  = openssl_decrypt($ct, self::CIPHER, self::aesKey($appKey), OPENSSL_RAW_DATA, $iv, $tag);

        return $pt === false ? null : $pt;
    }

    private function __construct() {}
}
