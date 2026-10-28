# 🚀 VampqweEngineFuture

Модульный PHP 8.1+ движок на чистой архитектуре **без внешних vendor-зависимостей**:
собственное HTTP-ядро, DI-контейнер, система хуков и самодиагностика (Doctor).

> ⚖️ **СТАТУС ДОКУМЕНТА.** Суть проекта, «Конституция», ключевые архитектурные решения
> и правила стиля ниже — **неизменны**. Они реконструированы из фактического кода
> (ссылки `п.N Конституции` в PHPDoc, проверки Doctor'ом) и являются обязательными
> для всех последующих изменений. Любое отступление от них — баг архитектуры, а не фича.

---

## 📌 Суть проекта

- Чистая архитектура: ядро (`core/System`) + модули ядра (`core/Module`) + пользовательские модули (`app/modules`).
- Ноль vendor-зависимостей: только PHP ≥ 8.1 и собственный PSR-4 автозагрузчик (`composer dump-autoload`).
- Модули собираются в контейнер по манифесту `config/modules.php` в строгом порядке уровней зависимостей.
- Движок «не падает»: состояния приложения, fallback-логгер, перехват фатальных ошибок, HTML ошибок рендерят модули, а не ядро.
- Самодиагностика встроена: CLI `php bin/engine doctor` и HTTP `GET /system/health` честно проверяют работоспособность компонентов.

---

## ⚖️ Основные принципы («Конституция»)

Реконструирована из кода; номера пунктов используются в PHPDoc по всему проекту.

| № | Принцип | Формулировка |
|---|---------|--------------|
| п.1 | **Чистое ядро** | Ядро не содержит бизнес-логики и не знает о конкретных модулях — только контракты, контейнер, HTTP-поток и загрузка манифеста. |
| п.2 | **Модульная инкапсуляция** | Публичная граница модуля — ТОЛЬКО папка `Contracts/`. `Internal/` запрещён к использованию извне модуля. Единственные осознанные исключения зафиксированы в коде (`FatalPage`, `SuperAdmin`). |
| п.3 | **Инверсия зависимостей** | Все зависимости приходят через контракты в конструкторах (DI). `new` конкретной реализации в бизнес-логике запрещён. Проверено тестами (`ContainerTest`). |
| п.4 | **Две дороги HTTP** | Системная (`/system/*`, `/admin/*`, `/install*`) и пользовательская (`/`, `/blog/*`…) маршрутизации; глобальные middleware обеих дорог (CSRF); расширение поведения — только через хук-фильтры. Проверено тестами (`RouterTest`). |
| п.5 | **Расширяемость через хуки** | Шина `Hook` (модель WordPress: фильтры `applyFilters()` / действия `dispatch()`) — единственный легальный способ расширять ядро без правки его кода. Ошибка слушателя изолируется и не ломает цепочку. |
| п.6 | **Порядок загрузки модулей** | Манифест `config/modules.php` — единственный источник истины о подключаемых модулях; порядок критичен и идёт по уровням зависимостей (0: FileSystem/Parser/ArrayHelper/Hook → 1: Config → 2: Logger/TimeDate → 3: Exception/Error → 4+: сервисные). |
| п.7 | **Единая точка правды о путях** | Все пути объявлены ТОЛЬКО в `Paths::MAP` (`core/System/Support/Paths.php`). Запрещены `dirname(__DIR__, N)` и конкатенации базового пути в потребителях; пути отдаются из контейнера по ключам `<ключ>_path`. Doctor сверяет регистрацию всех путей. |
| п.8 | **Конфигурация из .env** | `.env` — источник среды (пароль SuperAdmin хранится как argon2id-хеш `SUPER_ADMIN_HASH`); секреты не попадают в репозиторий; `.env.example` документирует состав. |
| п.9 | **Наблюдаемость** | Модуль Doctor выполняет реальные проверки (PHP, autoload, .env, права storage/, загрузка модулей, состояние, пути), а не декларирует «зелёный» статус вслепую; заглушки честно помечаются «в плане». |
| п.10 | **Отказоустойчивость** | Движок не падает: `set_exception_handler` + `set_error_handler` + `register_shutdown_function`, FallbackLogger при недоступности основного логгера, изоляция ошибок слушателей и модулей при boot. |

---

## 🏗 Ключевые архитектурные решения

- **DI-контейнер** (`core/System/Container`): ленивые синглтоны через `bind()`, готовые экземпляры через `instance()`, autowiring по reflection-конструкторам, защита от циклических зависимостей.
- **Состояния приложения** (`AppState`, enum): NotInstalled → Installing → Maintenance / Upgrading / Running / Error; управляются файлами-флагами в `storage/` (например `.installed`). Middleware `MaintenanceMode` перехватывает пользовательскую дорогу, системная остаётся доступной.
- **HTTP-ядро** (`core/System/Http`): Kernel с middleware-конвейером, Request/Response как значения, Router с параметрами `{id}`, отдельная обработка install-режима до полного boot.
- **Хук-шина** (`core/Module/Hook`): приоритеты (меньше = раньше), именованные подписки (`removeAction`), точечная нотация имён (`engine.page.content`, `engine.booted`).
- **Логирование** (`core/Module/Logger`): каналы по модулям, файлы `logs/<дата>/<Модуль>.log`, ротация датой; FallbackLogger гарантирует запись даже при отказе логгера.
- **Безопасность**: фиксированный SuperAdmin в ядре (константа, не БД — аналог root), CSRF-globally на обеих дорогах (кроме install), подписанные куки сессий, argon2id-хеширование паролей (Security).
- **Структура модуля** — единый канон: `Contracts/` (публичный API) + `Providers/` (регистрация в DI) + `Internal/` (закрытая реализация) + facade-класс модуля.
- **Тесты** — собственный минималистичный фреймворк (`tests/Framework/TestCase.php`, `tests/run.php`), чтобы не раздувать `composer.json` зависимостями.

---

## 🎨 Правила стиля (проверено по факту: 168/168 файлов)

Фактическая проверка репозитория: **все 168 PHP-файлов** (core, app, tests, config, public, migrations)
содержат `declare(strict_types=1)`. Это инвариант, а не рекомендация.

1. `declare(strict_types=1)` — **первая строка после `<?php` в каждом файле без исключений** (168/168 ✓).
2. PSR-4: `App\Core\` → `core/`, `App\` → `app/`, `Tests\` → `tests/`; регистр namespace совпадает с регистром директорий.
3. Только современные фичи PHP 8.1+: enums, readonly-свойства, match, named arguments, first-class callable syntax.
4. Подробные PHPDoc на русском у каждого класса/контракта со ссылкой на пункт Конституции (`п.N Конституции`), где это применимо.
5. Зависимости — только constructor property promotion + readonly; никаких сеттеров-сервисов и service locator внутри бизнес-логики.
6. Пути проекта — только через ключи контейнера из `Paths::MAP` (п.7); `dirname(__DIR__, N)` для построения путей проекта запрещён (бытовой `dirname()` от уже полученного пути файла — допустим).
7. Каждый модуль соблюдает канон папок `Contracts / Providers / Internal`; выход за границу `Internal/` другого модуля запрещён (п.2).
8. Ошибки пользователя рисуются модулями (Error/Installer через контракты), ядро умеет только аварийный `FatalPage` как последнее средство.

---

## 📁 Структура

```
├── public/          ← точка входа (index.php, router.php для php -S)
├── core/
│   ├── System/      ← ядро: App, Container, Http (Kernel, Request, Response, Middleware),
│   │                   Support (Paths, Router, EnvLoader, SuperAdmin, FatalPage), Enums (AppState)
│   └── Module/      ← 21 модуль ядра: FileSystem, Parser, ArrayHelper, Hook, Config, Logger,
│   │                   TimeDate, Exception, Error, Cache, Database, Security, Session, Template,
│   │                   Queue, Migration, Theme, Doctor, Installer, Admin, Assets
├── app/modules/     ← пользовательские модули (Pages, Auth, …) — тот же канон Contracts/Internal
├── config/          ← app.php, modules.php (манифест модулей, п.6)
├── view/            ← core_view (ошибки/установщик/админка) и app_view (темы мультисайта)
├── storage/         ← cache, sessions, queue + файлы-флаги состояния (.installed и др.)
├── logs/            ← логи по каналам: logs/<дата>/<Модуль>.log
├── database/        ← SQL-дампы схемы (db.sql)
├── migrations/      ← версии схемы БД (<ГГГГ_ММ_ДД_HHMMSS>_имя.php)
├── tests/           ← собственный фреймворк + Unit (Container, Router, Security, Hook, Paths, Cache, Queue, Migration, Theme, Assets, ArrayHelper, KernelBoundary T1–T3, SecretLeakCheck)
├── docs/            ← CONSTITUTION.md — реестр «правило → чем проверяется» (Теорема синхронизации)
├── .github/workflows/ci.yml ← merge-gate: lint-rules ∧ test ∧ doctor (sqlite-стенд из .env.example)
├── bin/             ← CLI: php bin/engine doctor | migrate | sites | site:add | test | lint-rules
└── vendor/          ← только composer-автозагрузчик (никаких сторонних пакетов)
```

## 🩺 Самодиагностика

- CLI: `php bin/engine doctor` — PHP, autoload, `.env`, права `storage/`, загрузка всех модулей ядра, регистрация путей (п.7), состояние.
- HTTP: `GET /system/health` — JSON-вердикт; доступен даже в режиме обслуживания.
- CI merge-gate (`.github/workflows/ci.yml`): `lint-rules ∧ test ∧ doctor` на PHP 8.1/8.3; для doctor собирается детерминированный sqlite-стенд из `.env.example` (`DB_DRIVER=sqlite`, `DB_SQLITE_ENABLED=true`, `DB_DATABASE_PATH=engine.sqlite`) — без реальных СУБД и секретов.

## ⚖️ Теорема синхронизации

> `lint-rules exit 0` **∧** `test exit 0` ⇒ код соответствует Конституции (п.1–п.10).

Реестр доказательств «правило → линтер R1–R11 / тест T1–T3» и легальные исключения
(аннотации `@constitution-exception`) — в [`docs/CONSTITUTION.md`](docs/CONSTITUTION.md);
формулировка теоремы продублирована в PHPDoc `ArchitectureLinter`. Нарушение, которое
не ловится ни одной проверкой, считается слепым пятном теоремы и закрывается новым
правилом или тестом — не аннотацией.

## 🚀 Быстрый старт

```bash
composer dump-autoload
cp .env.example .env
php -S localhost:8000 -t public public/router.php
```

Windows: `start-dev.ps1` (уже передаёт router-скрипт) или `init.ps1` для первичной подготовки.

## 🌐 Мультисайт (тема по домену)

Один движок обслуживает несколько сайтов: домен запроса резолвится в тему через реестр `engine_sites` (модуль Theme, `SiteRegistry`), затем — эвристика поддомена (`<theme>.localhost`), затем дефолт из `config/template.php`. Ядро при резолве вызывает хук `theme.resolved` (п.4) и авто-клонирует отсутствующую папку темы из стартера `view/app/_starter` (движок не падает).

```bash
php bin/engine migrate                      # создаёт таблицу engine_sites
php bin/engine site:add shop.test animals   # домен -> тема (клон из _starter при первом запросе)
php bin/engine sites                        # список сайтов реестра
```

📝 Лицензия: MIT
