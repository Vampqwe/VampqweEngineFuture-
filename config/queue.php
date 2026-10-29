<?php
declare(strict_types=1);

/**
 * Конфигурация модуля Queue.
 *
 * queue.driver: file (по умолчанию) | redis.
 * file-очередь живёт в ЕДИНОМ пути Paths::MAP['queue'] (storage/queue).
 */

return [
    'driver' => strtolower((string) ($_ENV['QUEUE_DRIVER'] ?? 'file')), // file|redis

    // Сколько раз задача может быть переотправлена worker'ом до переноса в failed/.
    'max_attempts' => (int) ($_ENV['QUEUE_MAX_ATTEMPTS'] ?? 3),

    // Базовая задержка retry (секунды) и пауза «видимости» задачи при pop().
    'retry_after'  => (int) ($_ENV['QUEUE_RETRY_AFTER'] ?? 60),
    'visibility'   => (int) ($_ENV['QUEUE_VISIBILITY'] ?? 30),

    'backends' => [
        'file' => [
            // Каталог бэкенда берётся из ЕДИНОЙ точки правды о путях (Paths::MAP['queue']).
            'path' => \App\Core\System\Support\Paths::class . '@queue',
        ],
        'redis' => [
            'enabled'  => filter_var($_ENV['QUEUE_REDIS_ENABLED'] ?? 'false', FILTER_VALIDATE_BOOLEAN),
            'host'     => (string) ($_ENV['QUEUE_REDIS_HOST'] ?? '127.0.0.1'),
            'port'     => (int) ($_ENV['QUEUE_REDIS_PORT'] ?? 6379),
            'password' => isset($_ENV['QUEUE_REDIS_PASSWORD']) ? (string) $_ENV['QUEUE_REDIS_PASSWORD'] : null,
            'database' => (int) ($_ENV['QUEUE_REDIS_DB'] ?? 0),
            'list'     => (string) ($_ENV['QUEUE_REDIS_LIST'] ?? 'engine:queue'),
        ],
    ],
];
