<?php
declare(strict_types=1);

/**
 * Манифест модулей ядра — ЕДИНСТВЕННЫЙ источник истины о подключаемых модулях.
 *
 * Формат: ModuleName => [
 *     'provider' => FQN провайдера,
 *     'critical' => критичен ли модуль для старта движка,
 * ]
 *
 * ПОРЯДОК КРИТИЧЕН (п.6 Конституции): модули идут по уровням зависимостей:
 *   0: FileSystem, Parser, ArrayHelper — фундамент, без зависимостей
 *   1: Config                        — зависит от FileSystem (парсер PHP-конфигов)
 *   2: Logger, TimeDate              — Logger <- FileSystem+Config; TimeDate <- Config
 *   3: Exception, Error              — обработчик/страницы ошибок <- Logger+Config
 *   4+: Cache, Database, Security, Template, Queue, Doctor, Installer
 *       — поздние модули <- Config (+ вышеперечисленные)
 *
 * App::registerModuleProviders() регистрирует провайдеры в этом порядке и
 * пропускает модули, чьих классов ещё нет (для некритичных).
 */
return [

    // --- Уровень 0: фундамент ---
    'FileSystem' => [
        'provider' => \App\Core\Module\FileSystem\Providers\FileSystemServiceProvider::class,
        'critical' => true,
    ],
    'Parser' => [
        'provider' => \App\Core\Module\Parser\Providers\ParserServiceProvider::class,
        'critical' => false,
    ],

    // Модуль работы с массивами: точечная нотация, mergeDeep, выборки.
    // Уровень 0 — зависимостей не имеет. Используется Config/Database/Template.
    'ArrayHelper' => [
        'provider' => \App\Core\Module\ArrayHelper\Providers\ArrayHelperServiceProvider::class,
        'critical' => false,
    ],

    // --- Уровень 1: конфигурация ---
    'Config' => [
        'provider' => \App\Core\Module\Config\Providers\ConfigServiceProvider::class,
        'critical' => true,
    ],

    // --- Уровень 2: журнал и время ---
    'Logger' => [
        'provider' => \App\Core\Module\Logger\Providers\LoggerServiceProvider::class,
        'critical' => true,
    ],
    'TimeDate' => [
        'provider' => \App\Core\Module\TimeDate\Providers\TimeDateServiceProvider::class,
        'critical' => false,
    ],

    // --- Уровень 3: ошибки ---
    'Exception' => [
        'provider' => \App\Core\Module\Exception\Providers\ExceptionServiceProvider::class,
        'critical' => true,
    ],
    'Error' => [
        'provider' => \App\Core\Module\Error\Providers\ErrorServiceProvider::class,
        'critical' => false,
    ],

    // --- Уровень 4+: сервисные модули (скелеты, реализация в плане) ---
    'Cache' => [
        'provider' => \App\Core\Module\Cache\Providers\CacheServiceProvider::class,
        'critical' => false,
    ],
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
    'Queue' => [
        'provider' => \App\Core\Module\Queue\Providers\QueueServiceProvider::class,
        'critical' => false,
    ],
    'Doctor' => [
        'provider' => \App\Core\Module\Doctor\Providers\DoctorServiceProvider::class,
        'critical' => false,
    ],
    'Installer' => [
        'provider' => \App\Core\Module\Installer\Providers\InstallerServiceProvider::class,
        'critical' => false,
    ],
];
