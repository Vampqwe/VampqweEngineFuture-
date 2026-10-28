# PR: План развития 1–5 + Asset-система (Этапы 0–3) + эталонная конфигурация

**Ветка:** `qwen-code-619c2ec7-8901-443f-ad87-83b4cf0e1d3c` → `main`
**Объём:** 18 коммитов, 72 файла, +4422 / −90 строк
**Верификация на HEAD:** `lint-rules` ✅ 0 нарушений · `test` ✅ все тесты зелёные · рабочее дерево чистое

---

## 📦 Что внутри (по логическим блокам)

### Блок A. Эталонный README (неизменный фундамент) — `f060348`, `a0a4958`
Полная переработка документации: суть проекта, «Конституция» (10 пунктов, реконструирована из PHPDoc кода), архитектурные решения, правила стиля (инвариант 129/129 `strict_types`). Статус документа помечен как неизменный — отступление от принципов = баг архитектуры.

### Блок B. Пункт 1 — модуль Pages (эталон прикладного модуля) — `dcc3860`
- `app/modules/Pages/` — Contracts (публичная граница) + Repository (slug-транслитерация к/л, уникальные слагги, CRUD) + Controller + ServiceProvider.
- Ядро подключает роут `/{slug}` **условно** (`$container->has(PageController::class)`) — нулевая связанность ядро↔приложение (п.2).
- Попутно: `DatabaseInterface::lastInsertId()`, фикс `QueryBuilder::update()` (возврат affected rows).

### Блок C. Пункты 2+5 — тесты и статический контроль Конституции — `7930d9e`
- `core/System/Support/ArchitectureLinter.php`: проверки R1–R5 (strict_types, запрет `dirname(__DIR__,N)` для путей, инкапсуляция `Internal/`, PSR-4, валидность манифеста).
- `bin/engine lint-rules` + composer-скрипт; 6 новых тест-сьют (Hook, Paths, Cache, Queue, Migration, ArrayHelper).
- Найден и исправлен реальный баг: `Migrator` вызывал `TimeDate::make()` с перепутанными аргументами (TypeError при каждом апдейте таблицы миграций).

### Блок D. Пункт 3 — модуль Auth — `0302e84`
- Session-based логин/регистрация с фиксацией ID сессии, CSRF через глобальный middleware ядра.
- Хуки по п.4: действия `auth.login` / `auth.logout` / `auth.registered`, фильтр `auth.register_allowed`.
- Миграция `users` (+UNIQUE email); шаблоны в теме `_default/auth/`; роуты условны и идут **до** `/{slug}`.

### Блок E. Пункт 4 — мультисайт — `b650c85` + фиксы `3094a73`, `63b5f85`
- `SiteRegistry`: резолв хост→сайт→тема, клон `_starter` при регистрации, хук `theme.resolved`.
- CLI: `php bin/engine site:add <host> <theme> "<title>"`, `sites`.
- Фиксы: эвристика поддоменов для bare-host, утечка темы между доменами (контекст резолвится перед каждым APP-рендером), потеря title.

### Блок F. Админ-панель как системный модуль (Вариант А) — `1b06919`, `63b5f85`, `3094a73`
- Инкапсулирована в `core/Module/Admin/` (Contracts + Internal: Guard, Controller, разделы Dashboard/Pages/Users/System).
- Шаблоны **вне темы**: `view/core/admin/` + layout `view/core/layouts/admin.phtml` (SCOPE_CORE) — презентационная зона тем не трогается.
- Навигация строится из реестра разделов; доступ через SuperAdmin/session-guard.

### Блок G. Asset-система, Этапы 1–3 — `e90b67c`, `dfdb72b`, `6b094fa`, `891295d`, `213ef59`
- `core/Module/Assets/`: декларативный реестр CSS/JS с каскадом **site → theme → core**, топосортировкой зависимостей, MIME-whitelist и защитой от path traversal.
- Раздача `/assets/*` через Kernel (PHP-раздача, Вариант А) с **cache-busting по content-hash** и HTTP-валидацией (ETag/Last-Modified → 304).
- Хуки расширения: `engine.assets.styles` / `engine.assets.scripts` — стили меняются без правки ядра.
- Вынос инлайн-стилей админки в `view/core/assets/css/admin.css`; стартовые ассеты тем (`_starter`, `animals`).
- Исправлен баг инвалидации версии файла внутри процесса (stat-отпечаток вместо слепого кэша).

### Блок H. Конфигурация (финал) — `9f390f8`, `7f9f467`, `76308b8`
- `.env.example` переписан как эталон: **50 ключей**, каждый реально читается кодом (проверено grep), комментарии по секциям + блок «осознанно НЕ в .env» (пути — только `Paths::MAP`, манифест модулей и т.п.).
- `.env` выведен из git-индекса (права 600 локально, добавлен в `.gitignore`).
- Удалён мёртвый ключ `APP_ENV` (не читался нигде, кроме записи самим Installer'ом; единственный источник режима — `APP_DEBUG`).
- Убран артефакт прогонов `storage/.session_store.file` из индекса + в `.gitignore`.

---

## ✅ Чек-лист качества
- [x] `php bin/engine lint-rules` — 0 нарушений Конституции
- [x] `php bin/engine test` — все тесты зелёные (включая AssetsTest, ThemeTest мультисайта)
- [x] Рабочее дерево чистое, секретов в индексе нет (`.env`, session-store — в gitignore)
- [x] Конституция соблюдена: Contracts/Internal, DI через контракты, Paths::MAP, хуки, условные роуты

## ⚠️ Примечания для ревьюера
- Обязателен `composer dump-autoload` после мержа (канонизация `App\modules\*` без явных PSR-4-префиксов).
- После установки: `php bin/engine migrate` (миграции users, engine_sites.title, slug-индекс).
- Smoke-сценарий мультисайта описан в README (раздел «🌐 Мультисайт»).
