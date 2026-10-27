<?php
declare(strict_types=1);

/**
 * Конфигурация модуля Cache.
 *
 * cache.driver: file (по умолчанию) | array | redis.
 * Redis отключён по умолчанию — включается через CACHE_REDIS_ENABLED=true
 * при наличии расширения ext-redis.
 */

return [
    'driver' => strtolower((string) ($_ENV['CACHE_DRIVER'] ?? 'file')), // file|array|redis

    'prefix' => (string) ($_ENV['CACHE_PREFIX'] ?? 'engine'),

    // TTL по умолчанию для set() без явного ttl (секунды; 0 = бессрочно).
    'ttl'    => (int) ($_ENV['CACHE_TTL'] ?? 3600),

    'stores' => [
        'file' => [
            // Каталог стора берётся из ЕДИНОЙ точки правды о путях (Paths::MAP['cache']).
            'path' => \App\Core\System\Support\Paths::class . '@cache',
        ],
        'redis' => [
            'enabled'   => filter_var($_ENV['CACHE_REDIS_ENABLED'] ?? 'false', FILTER_VALIDATE_BOOLEAN),
            'host'      => (string) ($_ENV['CACHE_REDIS_HOST'] ?? '127.0.0.1'),
            'port'      => (int) ($_ENV['CACHE_REDIS_PORT'] ?? 6379),
            'password'  => isset($_ENV['CACHE_REDIS_PASSWORD']) ? (string) $_ENV['CACHE_REDIS_PASSWORD'] : null,
            'database'  => (int) ($_ENV['CACHE_REDIS_DB'] ?? 0),
        ],
    ],
];
