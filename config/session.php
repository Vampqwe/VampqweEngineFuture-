<?php
declare(strict_types=1);

/**
 * Конфигурация модуля Session — единая точка правды (значения из .env).
 *
 * session.driver: file (по умолчанию) | redis.
 * При СМЕНЕ драйвера существующие сессии мигрируются безболезненно:
 * ModuleSession находит запись в старом сторе и переносит в новый,
 * а Kernel при старте выполняет массовую миграцию оставшихся записей.
 */
$truthy = static fn (?string $v, bool $default): bool => $v === null
    ? $default
    : filter_var($v, FILTER_VALIDATE_BOOLEAN);

return [
    'driver'    => strtolower((string) ($_ENV['SESSION_DRIVER'] ?? 'file')), // file|redis
    'lifetime'  => max(60, (int) ($_ENV['SESSION_LIFETIME'] ?? 7200)),       // секунд TTL
    'cookie'    => (string) ($_ENV['SESSION_COOKIE'] ?? 'VSESSID'),
    'path'      => '/',
    'domain'    => (string) ($_ENV['SESSION_DOMAIN'] ?? ''),
    'secure'    => $truthy($_ENV['SESSION_SECURE'] ?? null, false), // HTTPS-куки
    'same_site' => (string) ($_ENV['SESSION_SAME_SITE'] ?? 'Lax'),  // Lax|Strict|None
    'encrypt_cookies' => $truthy($_ENV['SESSION_ENCRYPT_COOKIES'] ?? null, true),

    'redis' => [
        'host'     => (string) ($_ENV['REDIS_HOST'] ?? '127.0.0.1'),
        'port'     => (int) ($_ENV['REDIS_PORT'] ?? 6379),
        'password' => (string) ($_ENV['REDIS_PASSWORD'] ?? ''),
        'database' => (int) ($_ENV['REDIS_DB'] ?? 0),
    ],
];
