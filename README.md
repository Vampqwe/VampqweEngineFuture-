# 🚀 VampqweEngineFuture

Модульный PHP 8.1+ движок с чистой архитектурой, DI-контейнером и самодиагностикой.

## 📋 Особенности (реализовано)

- PHP 8.1+ (Enum, readonly, match, named arguments), `declare(strict_types=1)` во всех файлах
- Модульная архитектура: Contracts / Providers / Internal / реализация
- DI-контейнер: bind (ленивые синглтоны), instance, autowiring, защита от циклов
- Две дороги маршрутизации: системная (`/system/*`, `/admin/*`) и пользовательская (`/`, `/blog/*`…), middleware (MaintenanceMode)
- Система состояний: NotInstalled, Installing, Maintenance, Upgrading, Running, Error
- Порядок загрузки модулей задаётся манифестом `config/modules.php`
- Единая точка путей: `BASE_PATH` только в `public/index.php`, остальное — из контейнера
- PSR-4: `App\Core\System\` → `core/System/`, `App\Core\Module\` → `core/Module/` (регистр совпадает!)
- Обработка ошибок: set_exception_handler + set_error_handler + register_shutdown_function, FallbackLogger
- CLI: `php bin/engine doctor` · HTTP: `/system/health` (JSON)

## 🧭 В планах (в коде ещё нет)

Database (MySQL/PostgreSQL/SQLite), Template/view-слой, Installer, полноценный Doctor-модуль, RateLimiter, миграции, тесты.

## 📁 Структура

```
├── public/        ← точка входа (index.php, router.php для php -S)
├── core/
│   ├── System/    ← ядро: App, Container, Http (Kernel, Request, Response, Middleware), Support (Router, EnvLoader), Enums
│   └── Module/    ← модули движка: FileSystem, Config, Logger, Exception
├── config/        ← app.php, modules.php (манифест модулей)
├── storage/       ← logs, cache, sessions, queue + файлы-флаги состояния (.installed и др.)
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
