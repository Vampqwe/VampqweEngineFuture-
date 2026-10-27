# 🚀 VampqweEngineFuture

Модульный PHP 8.1+ движок с чистой архитектурой, DI-контейнером и самодиагностикой.

## 📋 Особенности (реализовано)

- PHP 8.1+ (Enum, readonly, match, named arguments), `declare(strict_types=1)` во всех файлах
- Модульная архитектура: Contracts / Providers / Internal / реализация
- DI-контейнер: bind (ленивые синглтоны), instance, autowiring, защита от циклов
- Две дороги маршрутизации: системная (`/system/*`, `/admin/*`) и пользовательская (`/`, `/blog/*`…), middleware (MaintenanceMode)
- Система состояний: NotInstalled, Installing, Maintenance, Upgrading, Running, Error
- Порядок загрузки модулей задаётся манифестом `config/modules.php`
- Единая точка путей: класс `core/System/Support/Paths.php` (Paths::MAP); потребители берут `<ключ>_path` из контейнера; Doctor сверяет регистрацию всех путей
- PSR-4: `App\Core\System\` → `core/System/`, `App\Core\Module\` → `core/Module/` (регистр совпадает!)
- Обработка ошибок: set_exception_handler + set_error_handler + register_shutdown_function, FallbackLogger
- CLI: `php bin/engine doctor` · HTTP: `/system/health` (JSON)

## 🧭 В планах (в коде ещё нет)

Полные реализации Cache/Database/Queue/Template/Installer/Security(crypto), RateLimiter, миграции, тесты. Скелеты с контрактами уже в ядре; Doctor честно помечает их «заглушка (в плане)».

## 📁 Структура

```
├── public/        ← точка входа (index.php, router.php для php -S)
├── core/
│   ├── System/    ← ядро: App, Container, Http (Kernel, Request, Response, Middleware), Support (Router, EnvLoader), Enums
│   └── Module/    ← 15 модулей ядра: FileSystem, Parser, ArrayHelper, Config, Logger, TimeDate,
│                    Exception, Error, Cache, Database, Security, Template, Queue, Doctor, Installer
├── config/        ← app.php, modules.php (манифест модулей)
├── storage/       ← cache, sessions, queue + файлы-флаги состояния (.installed и др.)
├── logs/          ← логи по каналам: logs/<дата>/<Модуль>.log (каждый модуль — свой файл)
├── bin/           ← CLI (engine doctor)
└── vendor/        ← автозагрузка (composer dump-autoload)
```

## 🩺 Самодиагностика

- CLI: `php bin/engine doctor` — проверяет PHP, autoload, .env, права storage/, загрузку всех модулей ядра, состояние.
- HTTP: `GET /system/health` — JSON-вердикт; доступен даже в режиме обслуживания.

## 🚀 Быстрый старт

```bash
composer dump-autoload
cp .env.example .env
touch storage/.installed          # или дождитесь Installer (в планах)
php -S localhost:8000 -t public public/router.php
```

Windows: `start-dev.ps1` (уже передаёт router-скрипт).

📝 Лицензия: MIT
