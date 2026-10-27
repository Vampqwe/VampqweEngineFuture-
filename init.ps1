# ============================================
# VampqweEngineFuture - Инициализация структуры
# ============================================

$root = "h:\Server\OSPanel\home\VampqweEngineFuture"
Set-Location $root

Write-Host "🚀 Создаём структуру проекта VampqweEngineFuture..." -ForegroundColor Cyan

# ============================================
# 1. ТОЧКА ВХОДА (публичная часть)
# ============================================
New-Item -ItemType Directory -Force -Path "public" | Out-Null
New-Item -ItemType Directory -Force -Path "public\assets" | Out-Null

# ============================================
# 2. ПОЛЬЗОВАТЕЛЬСКОЕ ПРИЛОЖЕНИЕ
# ============================================
New-Item -ItemType Directory -Force -Path "app\modules\Welcome\Controllers" | Out-Null
New-Item -ItemType Directory -Force -Path "app\modules\Welcome\Services" | Out-Null
New-Item -ItemType Directory -Force -Path "app\modules\Welcome\Models" | Out-Null
New-Item -ItemType Directory -Force -Path "app\modules\Welcome\Repositories" | Out-Null
New-Item -ItemType Directory -Force -Path "app\modules\Welcome\Views" | Out-Null

New-Item -ItemType Directory -Force -Path "app\shared\Traits" | Out-Null
New-Item -ItemType Directory -Force -Path "app\shared\Helpers" | Out-Null
New-Item -ItemType Directory -Force -Path "app\shared\Enums" | Out-Null

New-Item -ItemType Directory -Force -Path "app\Http\Middleware" | Out-Null
New-Item -ItemType Directory -Force -Path "app\Http\Requests" | Out-Null
New-Item -ItemType Directory -Force -Path "app\Http\Responses" | Out-Null

# ============================================
# 3. ЯДРО ДВИЖКА (core/system)
# ============================================
New-Item -ItemType Directory -Force -Path "core\system\Http\Middleware" | Out-Null
New-Item -ItemType Directory -Force -Path "core\system\Container\Contracts" | Out-Null
New-Item -ItemType Directory -Force -Path "core\system\Contracts" | Out-Null
New-Item -ItemType Directory -Force -Path "core\system\Enums" | Out-Null
New-Item -ItemType Directory -Force -Path "core\system\Exceptions" | Out-Null

# ============================================
# 4. МОДУЛИ ДВИЖКА (core/module)
# ============================================

# --- Config ---
New-Item -ItemType Directory -Force -Path "core\module\Config\Providers" | Out-Null
New-Item -ItemType Directory -Force -Path "core\module\Config\Contracts" | Out-Null
New-Item -ItemType Directory -Force -Path "core\module\Config\Internal\Parsers" | Out-Null
New-Item -ItemType Directory -Force -Path "core\module\Config\Internal\Exceptions" | Out-Null

# --- Database ---
New-Item -ItemType Directory -Force -Path "core\module\Database\Providers" | Out-Null
New-Item -ItemType Directory -Force -Path "core\module\Database\Contracts" | Out-Null
New-Item -ItemType Directory -Force -Path "core\module\Database\Drivers" | Out-Null
New-Item -ItemType Directory -Force -Path "core\module\Database\QueryBuilder" | Out-Null

# --- Error ---
New-Item -ItemType Directory -Force -Path "core\module\Error\Providers" | Out-Null
New-Item -ItemType Directory -Force -Path "core\module\Error\Views" | Out-Null

# --- Installer ---
New-Item -ItemType Directory -Force -Path "core\module\Installer\Providers" | Out-Null
New-Item -ItemType Directory -Force -Path "core\module\Installer\Controllers" | Out-Null
New-Item -ItemType Directory -Force -Path "core\module\Installer\Steps" | Out-Null
New-Item -ItemType Directory -Force -Path "core\module\Installer\Views" | Out-Null

# --- Logger ---
New-Item -ItemType Directory -Force -Path "core\module\Logger\Providers" | Out-Null
New-Item -ItemType Directory -Force -Path "core\module\Logger\Contracts" | Out-Null
New-Item -ItemType Directory -Force -Path "core\module\Logger\Handlers" | Out-Null

# --- Security ---
New-Item -ItemType Directory -Force -Path "core\module\Security\Providers" | Out-Null
New-Item -ItemType Directory -Force -Path "core\module\Security\Hash" | Out-Null
New-Item -ItemType Directory -Force -Path "core\module\Security\Auth" | Out-Null
New-Item -ItemType Directory -Force -Path "core\module\Security\Csrf" | Out-Null

# --- Template ---
New-Item -ItemType Directory -Force -Path "core\module\Template\Providers" | Out-Null
New-Item -ItemType Directory -Force -Path "core\module\Template\Contracts" | Out-Null
New-Item -ItemType Directory -Force -Path "core\module\Template\Engines" | Out-Null

# --- Queue ---
New-Item -ItemType Directory -Force -Path "core\module\Queue\Providers" | Out-Null
New-Item -ItemType Directory -Force -Path "core\module\Queue\Contracts" | Out-Null
New-Item -ItemType Directory -Force -Path "core\module\Queue\Drivers" | Out-Null
New-Item -ItemType Directory -Force -Path "core\module\Queue\Jobs" | Out-Null

# --- Cache ---
New-Item -ItemType Directory -Force -Path "core\module\Cache\Providers" | Out-Null
New-Item -ItemType Directory -Force -Path "core\module\Cache\Contracts" | Out-Null
New-Item -ItemType Directory -Force -Path "core\module\Cache\Drivers" | Out-Null

# --- Maintenance ---
New-Item -ItemType Directory -Force -Path "core\module\Maintenance\Providers" | Out-Null
New-Item -ItemType Directory -Force -Path "core\module\Maintenance\Views" | Out-Null

# --- 🩺 DOCTOR (новый модуль!) ---
New-Item -ItemType Directory -Force -Path "core\module\Doctor\Contracts" | Out-Null
New-Item -ItemType Directory -Force -Path "core\module\Doctor\Enums" | Out-Null
New-Item -ItemType Directory -Force -Path "core\module\Doctor\Data" | Out-Null
New-Item -ItemType Directory -Force -Path "core\module\Doctor\Internal" | Out-Null
New-Item -ItemType Directory -Force -Path "core\module\Doctor\Providers" | Out-Null
New-Item -ItemType Directory -Force -Path "core\module\Doctor\Controllers" | Out-Null
New-Item -ItemType Directory -Force -Path "core\module\Doctor\Views" | Out-Null

# ============================================
# 5. КОНФИГИ, МАРШРУТЫ, ШАБЛОНЫ
# ============================================
New-Item -ItemType Directory -Force -Path "config" | Out-Null
New-Item -ItemType Directory -Force -Path "routes" | Out-Null

New-Item -ItemType Directory -Force -Path "view\system\emails" | Out-Null
New-Item -ItemType Directory -Force -Path "view\system\notifications" | Out-Null
New-Item -ItemType Directory -Force -Path "view\sites\main\layouts" | Out-Null
New-Item -ItemType Directory -Force -Path "view\sites\main\partials" | Out-Null
New-Item -ItemType Directory -Force -Path "view\sites\main\pages" | Out-Null
New-Item -ItemType Directory -Force -Path "view\sites\admin\layouts" | Out-Null
New-Item -ItemType Directory -Force -Path "view\sites\admin\pages" | Out-Null

# ============================================
# 6. СТАТИКА, ХРАНИЛИЩЕ, МИГРАЦИИ
# ============================================
New-Item -ItemType Directory -Force -Path "assets\css" | Out-Null
New-Item -ItemType Directory -Force -Path "assets\js" | Out-Null
New-Item -ItemType Directory -Force -Path "assets\fonts" | Out-Null
New-Item -ItemType Directory -Force -Path "assets\images" | Out-Null

New-Item -ItemType Directory -Force -Path "storage\cache" | Out-Null
New-Item -ItemType Directory -Force -Path "storage\logs" | Out-Null
New-Item -ItemType Directory -Force -Path "storage\sessions" | Out-Null
New-Item -ItemType Directory -Force -Path "storage\uploads" | Out-Null
New-Item -ItemType Directory -Force -Path "storage\queue" | Out-Null
New-Item -ItemType Directory -Force -Path "storage\backups" | Out-Null

New-Item -ItemType Directory -Force -Path "migrations" | Out-Null

# ============================================
# 7. ТЕСТЫ, CLI, VENDOR
# ============================================
New-Item -ItemType Directory -Force -Path "tests\Unit" | Out-Null
New-Item -ItemType Directory -Force -Path "tests\Feature" | Out-Null

New-Item -ItemType Directory -Force -Path "bin" | Out-Null
New-Item -ItemType Directory -Force -Path "vendor" | Out-Null

# ============================================
# 8. НАЧАЛЬНЫЕ ФАЙЛЫ
# ============================================

# .gitkeep для пустых папок (чтобы Git их увидел)
New-Item -ItemType File -Force -Path "storage\logs\.gitkeep" | Out-Null
New-Item -ItemType File -Force -Path "storage\cache\.gitkeep" | Out-Null
New-Item -ItemType File -Force -Path "storage\sessions\.gitkeep" | Out-Null
New-Item -ItemType File -Force -Path "storage\uploads\.gitkeep" | Out-Null
New-Item -ItemType File -Force -Path "storage\queue\.gitkeep" | Out-Null
New-Item -ItemType File -Force -Path "storage\backups\.gitkeep" | Out-Null
New-Item -ItemType File -Force -Path "vendor\.gitkeep" | Out-Null

# public/index.php — точка входа
@'
<?php
declare(strict_types=1);

// Точка входа VampqweEngineFuture
require_once __DIR__ . '/../vendor/autoload.php';

// Загрузка ядра
$app = require_once __DIR__ . '/../core/system/App.php';

// Запуск приложения
$app->run();
'@ | Set-Content -Path "public\index.php" -Encoding UTF8

# public/.htaccess
@'
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /
    
    # Если файл или папка существуют — отдаём напрямую
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    
    # Иначе — перенаправляем на index.php
    RewriteRule ^(.*)$ index.php [QSA,L]
</IfModule>

# Запрещаем доступ к системным файлам
<FilesMatch "\.(env|log|sql|md)$">
    Require all denied
</FilesMatch>
'@ | Set-Content -Path "public\.htaccess" -Encoding UTF8

# composer.json
@'
{
    "name": "vampqwe/engine-future",
    "description": "Модульный PHP 8+ движок с чистой архитектурой",
    "type": "project",
    "license": "MIT",
    "authors": [
        {
            "name": "Vampqwe",
            "email": "vampqwe@example.com"
        }
    ],
    "require": {
        "php": ">=8.1"
    },
    "autoload": {
        "psr-4": {
            "App\\Core\\": "core/",
            "App\\": "app/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Tests\\": "tests/"
        }
    },
    "minimum-stability": "stable",
    "prefer-stable": true
}
'@ | Set-Content -Path "composer.json" -Encoding UTF8

# .gitignore
@'
# Зависимости
/vendor/

# Данные приложения
/storage/cache/*
/storage/logs/*
/storage/sessions/*
/storage/uploads/*
/storage/queue/*
/storage/backups/*
!/storage/*/.gitkeep

# Флаги состояния
/storage/.installed
/storage/.installing
/storage/.maintenance

# Конфиги с секретными данными
/config/database.php
.env

# IDE
/.idea/
/.vscode/
*.swp
*.swo

# OS
Thumbs.db
.DS_Store

# Логи
*.log
'@ | Set-Content -Path ".gitignore" -Encoding UTF8

# .env.example
@'
# Режим отладки (true/false)
APP_DEBUG=true

# Часовой пояс
APP_TIMEZONE=Europe/Moscow

# Локаль
APP_LOCALE=ru

# База данных
DB_DRIVER=mysql
DB_HOST=localhost
DB_PORT=3306
DB_NAME=vampqwe_engine
DB_USER=root
DB_PASSWORD=

# Кеш
CACHE_DRIVER=file

# Очереди
QUEUE_DRIVER=database

# Логирование
LOG_LEVEL=debug
'@ | Set-Content -Path ".env.example" -Encoding UTF8

# config/app.php
@'
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
'@ | Set-Content -Path "config\app.php" -Encoding UTF8

# config/modules.php — манифест модулей (важно для Доктора!)
@'
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
'@ | Set-Content -Path "config\modules.php" -Encoding UTF8