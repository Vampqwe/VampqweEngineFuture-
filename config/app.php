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

    // ── Идентичность сайта (пользовательская дорога) ────────────────
    // Концепция: ОДИН ЗАПУСК ДВИЖКА = ОДИН САЙТ. Второй сайт — второй
    // экземпляр движка со своим BASE_URL_SITE (свой домен/поддомен/папка).
    // Это убирает путаницу мультисайта «по заголовку Host» на одном хостинге.
    //
    // Канонический URL этого экземпляра ('' = легаси-режим резолва по Host).
    'site_url' => rtrim(trim((string) ($_ENV['BASE_URL_SITE'] ?? '')), '/'),

    // Домен этого сайта: явный SITE_DOMAIN перебивает извлечённый из
    // BASE_URL_SITE (нужно за reverse-proxy с другим внешним хостом).
    'site_domain' => strtolower(trim((string) ($_ENV['SITE_DOMAIN'] ?? ''))),

    // true + заданный site_url/site_domain: запросы с чужого Host -> 404.
    'site_strict_host' => filter_var($_ENV['SITE_STRICT_HOST'] ?? false, FILTER_VALIDATE_BOOLEAN),
];
