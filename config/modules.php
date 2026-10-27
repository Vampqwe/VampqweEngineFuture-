<?php
declare(strict_types=1);

/**
 * Манифест модулей ядра.
 *
 * Формат: ModuleName => [
 *     'provider' => FQN провайдера,
 *     'critical' => критичен ли модуль для работы движка
 * ]
 *
 * ВАЖНО: этот файл — единственный источник истины о подключаемых модулях.
 * Возвращает массив с ключом 'path' (сам манифест) и списком модулей.
 * App::registerModuleProviders() читает его и пропускает модули, чьих
 * провайдеров ещё нет в коде (для некритичных). Критичные модули обязаны
 * существовать — иначе будет понятная ошибка на старте.
 */
return [
    'path' => 'config/modules.php',

    // --- Модули ядра, реализованные на текущем этапе ---
    'FileSystem' => [
        'provider' => \App\Core\Module\FileSystem\Providers\FileSystemServiceProvider::class,
        'critical' => true,
    ],
    'Config' => [
        'provider' => \App\Core\Module\Config\Providers\ConfigServiceProvider::class,
        'critical' => true,
    ],
    'Logger' => [
        'provider' => \App\Core\Module\Logger\Providers\LoggerServiceProvider::class,
        'critical' => true,
    ],
    'Exception' => [
        'provider' => \App\Core\Module\Exception\Providers\ExceptionServiceProvider::class,
        'critical' => true,
    ],

    // --- Запланированные модули (ещё не реализованы) ---
    // Раскомментируйте секцию, когда появятся соответствующие провайдеры.
    /*
    'Database' => [
        'provider' => \App\Core\Module\Database\Providers\DatabaseServiceProvider::class,
        'critical' => false,
    ],
    'Security' => [
        'provider' => \App\Core\Module\Security\Providers\SecurityServiceProvider::class,
        'critical' => false,
    ],
    'Template' => [
        'provider' => \App\Core\Module\Template\Providers\TemplateServiceProvider::class,
        'critical' => false,
    ],
    'Installer' => [
        'provider' => \App\Core\Module\Installer\Providers\InstallerServiceProvider::class,
        'critical' => false,
    ],
    'Doctor' => [
        'provider' => \App\Core\Module\Doctor\Providers\DoctorServiceProvider::class,
        'critical' => false,
    ],
    'Maintenance' => [
        'provider' => \App\Core\Module\Maintenance\Providers\MaintenanceServiceProvider::class,
        'critical' => false,
    ],
    'Cache' => [
        'provider' => \App\Core\Module\Cache\Providers\CacheServiceProvider::class,
        'critical' => false,
    ],
    'Queue' => [
        'provider' => \App\Core\Module\Queue\Providers\QueueServiceProvider::class,
        'critical' => false,
    ],
    */
];
