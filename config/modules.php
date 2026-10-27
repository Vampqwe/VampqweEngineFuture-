<?php
declare(strict_types=1);

/**
 * Манифест модулей ядра.
 * 
 * Формат: ModuleName => [
 *     'provider' => FQN провайдера,
 *     'critical' => критичен ли модуль для работы движка
 * ]
 */
return [
    'Config' => [
        'provider' => \App\Core\Module\Config\Providers\ConfigServiceProvider::class,
        'critical' => true,
    ],
    'Database' => [
        'provider' => \App\Core\Module\Database\Providers\DatabaseServiceProvider::class,
        'critical' => true,
    ],
    'Error' => [
        'provider' => \App\Core\Module\Error\Providers\ErrorServiceProvider::class,
        'critical' => true,
    ],
    'Logger' => [
        'provider' => \App\Core\Module\Logger\Providers\LoggerServiceProvider::class,
        'critical' => true,
    ],
    'Security' => [
        'provider' => \App\Core\Module\Security\Providers\SecurityServiceProvider::class,
        'critical' => true,
    ],
    'Template' => [
        'provider' => \App\Core\Module\Template\Providers\TemplateServiceProvider::class,
        'critical' => true,
    ],
    'Installer' => [
        'provider' => \App\Core\Module\Installer\Providers\InstallerServiceProvider::class,
        'critical' => true,
    ],
    'Doctor' => [
        'provider' => \App\Core\Module\Doctor\Providers\DoctorServiceProvider::class,
        'critical' => true,
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
];
