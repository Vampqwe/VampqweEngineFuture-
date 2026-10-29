<?php
declare(strict_types=1);

namespace App\Core\Module\Session\Internal;

/**
 * Внутренняя кухня модуля Session: сериализация подписанных значений куки.
 * Формат: <kind><base64url(payload)>.<hmac-sha256(name|payload)>
 * kind: 'p:' — подпись (plain), 'v:' — шифрование (encrypt).
 * ЗАПРЕЩЕНО использовать извне модуля (п.2 Конституции).
 */
final class Cookie
{
    public static function encode(string $value, string $key, bool $encrypted = false): string
    {
        $prefix = $encrypted ? 'v:' : 'p:';
        $b64 = rtrim(strtr(base64_encode($value), '+/', '-_'), '=');

        return $prefix . $b64;
    }

    /** @return array<string,mixed>|null null если формат битый */
    public static function decode(string $encoded): ?array
    {
        if (strlen($encoded) < 3) {
            return null;
        }
        $prefix = substr($encoded, 0, 2);
        if ($prefix !== 'p:' && $prefix !== 'v:') {
            return null;
        }
        $body = substr($encoded, 2);
        // base64url -> standard base64
        $std = strtr($body, '-_', '+/');
        $pad = strlen($std) % 4 === 0 ? '' : str_repeat('=', 4 - strlen($std) % 4);
        $decoded = base64_decode($std . $pad, true);
        if ($decoded === false) {
            return null;
        }

        return ['encrypted' => $prefix === 'v:', 'value' => $decoded];
    }

    public static function sign(string $name, string $encoded, string $key): string
    {
        return hash_hmac('sha256', $name . '|' . $encoded, $key);
    }

    public static function verify(string $name, string $encoded, string $sig, string $key): bool
    {
        return is_string($sig) && $sig !== '' && hash_equals(self::sign($name, $encoded, $key), $sig);
    }

    private function __construct() {}
}
