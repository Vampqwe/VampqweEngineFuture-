<?php
declare(strict_types=1);

/**
 * Конфигурация СУБД — единая точка правды о драйверах.
 * Значения берутся из .env (EnvLoader), дефолты безопасны до установки.
 *
 * ПО УМОЛЧАНИЮ ВКЛЮЧЁН ТОЛЬКО mysql. Остальные драйверы — enabled: false,
 * включаются одной переменю окружения: DB_MYSQL_ENABLED / DB_PGSQL_ENABLED / DB_SQLITE_ENABLED=true.
 */

$truthy = static fn (?string $v, bool $default): bool => $v === null
    ? $default
    : filter_var($v, FILTER_VALIDATE_BOOLEAN);

return [
    // Активный драйвер (mysql|pgsql|sqlite). Дефолт — mysql.
    'driver' => strtolower((string) ($_ENV['DB_DRIVER'] ?? 'mysql')),

    'connections' => [
        'mysql' => [
            'enabled'  => $truthy($_ENV['DB_MYSQL_ENABLED'] ?? null, true),   // ← по умолчанию ВКЛ
            'driver'   => 'mysql',
            'host'     => (string) ($_ENV['DB_HOST'] ?? '127.0.0.1'),
            'port'     => (int) ($_ENV['DB_PORT'] ?? 3306),
            'database' => (string) ($_ENV['DB_NAME'] ?? 'vampqwe_engine'),
            'username' => (string) ($_ENV['DB_USER'] ?? 'root'),
            'password' => (string) ($_ENV['DB_PASSWORD'] ?? ''),
            'charset'  => 'utf8mb4',
        ],

        'pgsql' => [
            'enabled'  => $truthy($_ENV['DB_PGSQL_ENABLED'] ?? null, false),  // ← выкл
            'driver'   => 'pgsql',
            'host'     => (string) ($_ENV['DB_HOST'] ?? '127.0.0.1'),
            'port'     => (int) ($_ENV['DB_PORT'] ?? 5432),
            'database' => (string) ($_ENV['DB_NAME'] ?? 'vampqwe_engine'),
            'username' => (string) ($_ENV['DB_USER'] ?? 'postgres'),
            'password' => (string) ($_ENV['DB_PASSWORD'] ?? ''),
            'charset'  => 'utf8',
        ],

        'sqlite' => [
            'enabled'  => $truthy($_ENV['DB_SQLITE_ENABLED'] ?? null, false), // ← выкл
            'driver'   => 'sqlite',
            // Путь к файлу БД; относительный резолвится от storage_path в коннекторе.
            // ВАЖНО: '' или пробелы = «не задано» — иначе PDO получает пустой путь
            // и падает с "unable to open database file" вместо дефолта.
            'database' => trim((string) ($_ENV['DB_DATABASE_PATH'] ?? '')) !== ''
                ? trim((string) $_ENV['DB_DATABASE_PATH'])
                : 'database.sqlite',
        ],
    ],

    // Общие PDO-настройки (безопасность и удобство — только prepared statements!)
    'fetch_mode'  => 'assoc',      // строки как массивы, не объекты
    'emulate_prep' => false,       // нативные подготовленные выражения PDO
    'timeout'      => 5,           // секунды на установление соединения
];
