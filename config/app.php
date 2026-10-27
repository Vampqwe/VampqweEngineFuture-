<?php
declare(strict_types=1);

return [
    // Имя приложения: единый источник — APP_NAME из .env (его пишет Installer).
    // Хардкод ниже — только fallback до установки/без .env.
    'name' => (string) ($_ENV['APP_NAME'] ?? 'VampqweEngineFuture'),
    'version' => '0.1.0',
    // (bool) 'false' === true в PHP! Поэтому сравниваем строку явно.
    // Безопасный дефолт: если APP_DEBUG не задан — debug ВЫКЛЮЧЕН.
    'debug' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
    'timezone' => $_ENV['APP_TIMEZONE'] ?? 'UTC',
    'locale' => $_ENV['APP_LOCALE'] ?? 'ru',
    'charset' => 'UTF-8',
    'log_level' => strtolower((string) ($_ENV['LOG_LEVEL'] ?? 'debug')),
];
