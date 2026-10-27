<?php

declare(strict_types=1);

/**
 * Router-скрипт для встроенного PHP-сервера (php -S).
 *
 * Без него встроенный сервер не применяет правила .htaccess (они только
 * для Apache) и отдаёт 404 для всех URL, кроме существующих файлов.
 * Здесь: статика отдаётся как есть, всё остальное — в public/index.php.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$file = __DIR__ . $path;

// Существующий статический файл (css/js/img) — отдаём напрямую
if ($path !== '/' && is_file($file)) {
    return false;
}

require __DIR__ . '/index.php';
