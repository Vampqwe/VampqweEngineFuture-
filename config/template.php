<?php
declare(strict_types=1);

/**
 * Конфигурация модуля Template.
 * Пути НЕ здесь — они в Paths::MAP (п.7 Конституции): core_view_path, app_view_path.
 */

return [
    // Расширение файлов шаблонов.
    'extension' => 'phtml',

    // Тема по умолчанию для пользовательской дороги (view/app/<theme>/...).
    // Мультисайт: конкретный сайт привязывается к теме из кода приложения
    // (например, $template->setSite('animals')), фолбэк — сюда.
    'default_theme' => (string) ($_ENV['TEMPLATE_THEME'] ?? '_default'),

    // Компиляция .phtml -> PHP-код в кэш (storage/cache/template).
    'cache' => [
        // false — всегда компилировать заново (dev); true — перекомпиляция только при изменении исходника.
        'enabled' => filter_var($_ENV['TEMPLATE_CACHE'] ?? 'true', FILTER_VALIDATE_BOOLEAN),
    ],
];
