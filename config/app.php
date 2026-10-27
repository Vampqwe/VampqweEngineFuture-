<?php
declare(strict_types=1);

return [
    'name' => 'VampqweEngineFuture',
    'version' => '0.1.0',
    'debug' => (bool) ($_ENV['APP_DEBUG'] ?? true),
    'timezone' => $_ENV['APP_TIMEZONE'] ?? 'Europe/Moscow',
    'locale' => $_ENV['APP_LOCALE'] ?? 'ru',
    'charset' => 'UTF-8',
];
