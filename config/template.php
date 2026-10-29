<?php
declare(strict_types=1);

/**
 * Конфигурация модуля Template.
 * Пути НЕ здесь — единая точка правды о путях: core/System/Support/Paths.php (п.7).
 */
return [
    // Кэш скомпилированных шаблонов (storage/cache/templates). false = всегда компилировать (dev-отладка).
    'cache' => filter_var($_ENV['TEMPLATE_CACHE'] ?? 'true', FILTER_VALIDATE_BOOLEAN),

    // Тема пользовательского сайта по умолчанию (view/app/<theme>/); мультисайт переключает через setTheme().
    'theme' => (string) ($_ENV['APP_THEME'] ?? 'engineStart'),
];
