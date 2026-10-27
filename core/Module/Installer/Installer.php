<?php
declare(strict_types=1);

namespace App\Core\Module\Installer;

use App\Core\Module\Config\Contracts\ConfigRepositoryInterface;
use App\Core\Module\Database\Contracts\DatabaseInterface;
use App\Core\Module\FileSystem\Contracts\FileSystemInterface;
use App\Core\Module\Installer\Contracts\InstallerInterface;
use App\Core\Module\Installer\Contracts\InstallerPageInterface;
use App\Core\Module\Installer\Internal\InstallerView;
use App\Core\Module\Installer\Internal\Steps\CreateEnvStep;
use App\Core\Module\Logger\Contracts\LoggerInterface;
use App\Core\Module\Migration\Contracts\MigratorInterface;
use App\Core\Module\Security\Contracts\SecurityInterface;
use App\Core\System\Support\SuperAdmin;
use Throwable;

/**
 * Фасад модуля Installer — первичная установка движка.
 *
 * Зависимости (только через контракты, п.3):
 *   FileSystemInterface — флаги/файлы; SecurityInterface — app.key;
 *   ConfigRepositoryInterface — настройки; LoggerInterface — канал 'Installer' (свой лог-файл).
 *
 * Поток: GET /install -> требования -> POST /install/settings (форма) ->
 *        POST /install/run -> install() -> success.
 * Database-шаг опционален и НЕ критичен: если активный драйвер недоступен,
 * установка продолжается (флаг .installed всё равно ставится), а ошибка уходит
 * в лог канала 'Installer' — движок не падает из-за соседей (п.9).
 */
final class Installer implements InstallerInterface, InstallerPageInterface
{
    private const FLAG_INSTALLED  = '.installed';
    private const FLAG_INSTALLING = '.installing';

    public function __construct(
        private readonly string $basePath,
        private readonly string $storagePath,
        private readonly FileSystemInterface $fs,
        private readonly SecurityInterface $security,
        private readonly ConfigRepositoryInterface $config,
        private readonly LoggerInterface $logger,
        private readonly ?DatabaseInterface $db = null,
        private readonly string $databasePath = '',
        // Migration — опциональная зависимость: после развёртки db.sql автоматически
        // применяются ожидающие версии схемы (migrations/ <- Paths).
        private readonly ?MigratorInterface $migrator = null,
        // Template — опциональная зависимость для страниц установщика (view/core/installer/*);
        // без неё — graceful fallback на внутренний InstallerView.
        private readonly ?\App\Core\Module\Template\Contracts\TemplateInterface $views = null,
    ) {
    }

    // ==================== InstallerInterface ====================

    /** @return array<string,bool> label => ok */
    public function requirements(): array
    {
        $checks = [
            'PHP >= 8.1'          => PHP_VERSION_ID >= 80100,
            'Расширение json'     => extension_loaded('json'),
            'Расширение mbstring' => extension_loaded('mbstring'),
            'Расширение PDO'      => extension_loaded('pdo'),
            'storage записываем'  => is_writable($this->storagePath),
            'logs записываем'     => $this->ensureWritableDir($this->logDirFromPaths()),
        ];

        // Драйвер БД: активный по умолчанию mysql — проверяем pdo_mysql;
        // включённые доп. драйверы проверяются только если реально enabled в конфиге.
        $driver = strtolower((string) $this->config->get('database.driver', 'mysql'));
        $checks['PDO-драйвер: ' . $driver] = in_array($driver, \PDO::getAvailableDrivers(), true);

        return $checks;
    }

    /**
     * Выполняет установку (env -> флаги -> database -> схема db.sql).
     *
     * @param array<string,mixed> $options
     * @return array<string,string>|true  true при успехе без деталей;
     *         массив «шаг => результат» — чтобы UI/CLI показывали итог БД и схемы.
     */
    public function install(array $options = []): array|bool
    {
        $flagFile = $this->storagePath . '/' . self::FLAG_INSTALLING;
        $this->fs->createFile($flagFile);
        $summary = [];

        try {
            // app.key генерируется один раз; существующий .env не перетираем
            $key = $this->currentAppKey() ?? 'base64:' . $this->security->randomToken(32);

            // Супер-администратор зашит в ядро (Support\SuperAdmin): логин фиксирован,
            // Installer хранит только argon2id-хеш его пароля в .env. Пароль/хеш в логи не пишутся.
            $password = (string) ($options['super_admin_password'] ?? '');
            if (strlen($password) < 8) {
                throw new \InvalidArgumentException('Пароль супер-администратора слишком короткий (мин. 8 символов).');
            }
            $adminHash = $this->security->hashPassword($password);
            unset($password);

            (new CreateEnvStep($this->fs))->handle($this->basePath . '/.env', [
                'APP_NAME'     => $this->sanitizeName((string) ($options['app_name'] ?? 'VampqweEngineFuture')),
                'APP_ENV'      => (bool) ($options['debug'] ?? false) ? 'local' : 'production',
                'APP_DEBUG'    => (bool) ($options['debug'] ?? false) ? 'true' : 'false',
                'APP_KEY'      => $key,
                'APP_TIMEZONE' => $this->sanitizeTimezone((string) ($options['timezone'] ?? 'UTC')),
                'LOG_LEVEL'    => 'debug',
                SuperAdmin::PASSWORD_HASH_ENV => $adminHash,
            ]);

            // Флаг готовности — AppState::Running определяется по нему
            $this->fs->createFile($this->storagePath . '/' . self::FLAG_INSTALLED);

            // Database-шаг: создаём БД (если активный драйвер включён и доступен)
            // и прогоняем схему database/db.sql. Недоступная СУБД НЕ валит установку —
            // ошибка уходит в лог канала Installer (п.9: движок не падает из-за соседей).
            $summary['database'] = $this->tryCreateDatabase();
            $summary['schema']   = $this->applySchema();

            $this->logger->info('Установка завершена успешно', ['channel' => 'Installer']);

            // Флаг .installed ставим ТОЛЬКО после успешной схемы. Иначе при недоступной
            // СУБД движок считался бы установленным с пустой базой (рассинхрон состояний).
            if ($this->isSchemaFailure($summary['schema'] ?? '')) {
                $this->fs->delete($this->storagePath . '/' . self::FLAG_INSTALLED);

                return false;
            }

            return $summary;
        } catch (Throwable $e) {
            $this->logger->error('Установка прервана: ' . $e->getMessage(), [
                'channel' => 'Installer',
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);

            return false;
        } finally {
            $this->fs->delete($flagFile);
        }
    }

    public function isInstalling(): bool
    {
        return $this->fs->isFile($this->storagePath . '/' . self::FLAG_INSTALLING);
    }

    public function isInstalled(): bool
    {
        return $this->fs->isFile($this->storagePath . '/' . self::FLAG_INSTALLED);
    }

    // ==================== InstallerPageInterface ====================
    //
    // Страницы рендерятся через модуль Template (view/core/installer/*).
    // Если Template ещё не зарегистрирован (ранняя стадия бутстрапа) —
    // graceful fallback на внутренний InstallerView, чтобы инсталлятор
    // работал даже при частичной инициализации ядра.

    public function requirementsPage(array $checks): string
    {
        if ($this->views() !== null) {
            $rows = [];
            foreach ($checks as $label => $ok) {
                $rows[] = [
                    'label'   => (string) $label,
                    'class'   => $ok ? 'ok' : 'fail',
                    'icon'    => $ok ? '&#10004;' : '&#10008;',
                    'verdict' => $ok ? 'OK' : 'НЕ ОБНАРУЖЕНО',
                ];
            }
            return $this->renderOrFallback(
                'installer/requirements',
                ['title' => 'Установка VampqweEngineFuture', 'rows' => $rows,
                 'allOk' => !in_array(false, $checks, true), 'step' => 1],
                fn(): string => InstallerView::page('Установка VampqweEngineFuture',
                    '<div class="sub">Проверка окружения перед установкой движка.</div>'
                    . '<h2>Требования</h2><table>' . $this->legacyRows($checks) . '</table>'
                    . (!in_array(false, $checks, true)
                        ? '<form method="post" action="/install/settings"><button>Перейти к настройкам &rarr;</button></form>'
                        : '<div class="errbox">Устраните проблемы выше и обновите страницу.</div>'),
                    ['step' => 1])
            );
        }

        return InstallerView::page('Установка VampqweEngineFuture',
            '<div class="sub">Проверка окружения перед установкой движка.</div>'
            . '<h2>Требования</h2><table>' . $this->legacyRows($checks) . '</table>'
            . (!in_array(false, $checks, true)
                ? '<form method="post" action="/install/settings"><button>Перейти к настройкам &rarr;</button></form>'
                : '<div class="errbox">Устраните проблемы выше и обновите страницу.</div>'),
            ['step' => 1]);
    }

    public function settingsForm(array $errors = []): string
    {
        $name = (string) $this->config->get('app.name', 'VampqweEngineFuture');

        if ($this->views() !== null) {
            return $this->renderOrFallback(
                'installer/settings',
                ['title' => 'Настройка движка', 'errors' => $errors, 'appName' => $name,
                 'timezones' => \DateTimeZone::listIdentifiers(), 'selectedTz' => 'Europe/Moscow', 'step' => 2],
                fn(): string => $this->legacySettingsForm($errors)
            );
        }

        return $this->legacySettingsForm($errors);
    }

    public function successPage(array $summary): string
    {
        if ($this->views() !== null) {
            return $this->renderOrFallback(
                'installer/success',
                ['title' => 'Установка завершена', 'summary' => $summary, 'step' => 3],
                fn(): string => $this->legacySuccess($summary)
            );
        }

        return $this->legacySuccess($summary);
    }

    public function failurePage(string $reason): string
    {
        if ($this->views() !== null) {
            return $this->renderOrFallback(
                'installer/failure',
                ['title' => 'Ошибка установки', 'reason' => $reason, 'step' => 2],
                fn(): string => $this->legacyFailure($reason)
            );
        }

        return $this->legacyFailure($reason);
    }

    // ==================== page-rendering helpers ====================

    /** Шаблонный рендер с защитой: сломанный шаблон -> legacy-страница, не фатал. */
    private function renderOrFallback(string $tpl, array $data, callable $fallback): string
    {
        try {
            $views = $this->views();
            if ($views !== null && $views->exists($tpl, \App\Core\Module\Template\Contracts\TemplateInterface::SCOPE_CORE)) {
                return $views->renderCore($tpl, $data);
            }
        } catch (\Throwable) {
            // шаблон бросил — отдаём legacy-версию вместо падения установщика
        }

        return $fallback();
    }

    /** Template доступен? (иначе — fallback InstallerView). */
    private function views(): ?\App\Core\Module\Template\Contracts\TemplateInterface
    {
        return $this->views;
    }

    private function legacyRows(array $checks): string
    {
        $rows = '';
        foreach ($checks as $label => $ok) {
            $rows .= '<tr><td>' . InstallerView::esc((string) $label) . '</td>'
                   . '<td class="' . ($ok ? 'ok' : 'fail') . '">'
                   . ($ok ? '&#10004; OK' : '&#10008; НЕ ОБНАРУЖЕНО') . '</td></tr>';
        }
        return $rows;
    }

    private function legacySettingsForm(array $errors): string
    {
        $errBox = '';
        if ($errors !== []) {
            $errBox = '<div class="errbox">' . implode('<br>', array_map([InstallerView::class, 'esc'], $errors)) . '</div>';
        }

        $tzOptions = '';
        foreach (\DateTimeZone::listIdentifiers() as $id) {
            $sel = $id === 'Europe/Moscow' ? ' selected' : '';
            $tzOptions .= '<option value="' . InstallerView::esc($id) . '"' . $sel . '>' . InstallerView::esc($id) . '</option>';
        }

        $name = InstallerView::esc((string) $this->config->get('app.name', 'VampqweEngineFuture'));

        // Поле пароля супер-администратора — имя НЕ выводится в HTML:
        // логин зашит в ядро (Support\SuperAdmin), пользователю нужен только пароль.
        $body = $errBox
              . '<div class="sub">Шаг 2 из 3. Настройки можно поменять позже в <code>.env</code>.</div>'
              . '<form method="post" action="/install/run">'
              . '<label>Название сайта</label>'
              . '<input name="app_name" value="' . $name . '" maxlength="64" required>'
              . '<label>Пароль супер-администратора <small>(единственный управленец движка, логин зашит в ядро)</small></label>'
              . '<input type="password" name="super_admin_password" minlength="8" autocomplete="new-password" required>'
              . '<label>Часовой пояс</label>'
              . '<select name="timezone">' . $tzOptions . '</select>'
              . '<label style="display:flex;gap:8px;align-items:center;margin-top:14px">'
              . '<input type="checkbox" name="debug" value="1" style="width:auto"> Режим отладки (показывать трейсы ошибок)</label>'
              . '<button>Установить</button></form>';

        return InstallerView::page('Настройка движка', $body, ['step' => 2]);
    }

    private function legacySuccess(array $summary): string
    {
        $rows = '';
        foreach ($summary as $k => $v) {
            $rows .= '<tr><td>' . InstallerView::esc((string) $k) . '</td><td><code>'
                  . InstallerView::esc(is_bool($v) ? ($v ? 'да' : 'нет') : (string) $v) . '</code></td></tr>';
        }

        $body = '<div class="success">Движок установлен и готов к работе!</div>'
              . '<table>' . $rows . '</table>'
              . '<a href="/" style="color:#a9c7ff">&rarr; Открыть сайт</a>';

        return InstallerView::page('Установка завершена', $body, ['step' => 3]);
    }

    private function legacyFailure(string $reason): string
    {
        $body = '<div class="errbox">&#10060; ' . InstallerView::esc($reason) . '</div>'
              . '<form method="get" action="/install"><button>Попробовать снова</button></form>';

        return InstallerView::page('Ошибка установки', $body, ['step' => 2]);
    }

    // ==================== Internal helpers ====================

    private function currentAppKey(): ?string
    {
        $envFile = $this->basePath . '/.env';
        if (!$this->fs->isFile($envFile)) {
            return isset($_ENV['APP_KEY']) ? (string) $_ENV['APP_KEY'] : null;
        }
        foreach (explode("\n", $this->fs->read($envFile)) as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'APP_KEY=')) {
                return substr($line, 8);
            }
        }
        return null;
    }

    /** Валидация POST-настроек; пустой массив — всё корректно. */
    public static function validateSettings(array $post): array
    {
        $errors = [];

        $name = trim((string) ($post['app_name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 64) {
            $errors[] = 'Название сайта: от 1 до 64 символов.';
        }
        if (!in_array((string) ($post['timezone'] ?? ''), \DateTimeZone::listIdentifiers(), true)) {
            $errors[] = 'Недопустимый часовой пояс.';
        }
        // Пароль супер-администратора обязателен с первого запуска (ядро, Support\SuperAdmin).
        $pass = (string) ($post['super_admin_password'] ?? '');
        if (strlen($pass) < 8 || strlen($pass) > 200) {
            $errors[] = 'Пароль супер-администратора: от 8 до 200 символов.';
        }

        return $errors;
    }

    private function sanitizeName(string $name): string
    {
        $clean = preg_replace('/[^A-Za-zА-Яа-яЁё0-9 _\-]/u', '', $name);
        return ($clean === null || $clean === '') ? 'VampqweEngineFuture' : $clean;
    }

    private function sanitizeTimezone(string $tz): string
    {
        return in_array($tz, \DateTimeZone::listIdentifiers(), true) ? $tz : 'UTC';
    }

    /** Каталог логов берём из config (app.logs_path регистрируется Paths) — без хардкода. */
    private function logDirFromPaths(): string
    {
        $p = $this->config->get('paths.logs');

        return is_string($p) && $p !== '' ? $p : $this->basePath . DIRECTORY_SEPARATOR . 'logs';
    }

    /** Установка считается неудачной, если схема не была развёрнута (СУБД/дамп недоступны). */
    private function isSchemaFailure(string $schemaResult): bool
    {
        return str_starts_with($schemaResult, 'пропущено')
            || str_starts_with($schemaResult, 'предупреждение');
    }

    /**
     * Имена таблиц из SQL-дампа (CREATE TABLE [IF NOT EXISTS] `name`).
     * Используется для честной проверки идемпотентности схемы.
     *
     * @return list<string>
     */
    private function dumpTables(string $sql): array
    {
        preg_match_all(
            '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"\[]?([A-Za-z0-9_]+)[`"\]]?/i',
            $sql,
            $m,
        );

        return array_values(array_unique($m[1] ?? []));
    }

    /**
     * Schema-шаг установки: прогон дампа database/db.sql через Database::migrate().
     * Идемпотентно: если таблица схемы уже существует — повторный пропуск не выполняется.
     * Путь к папке приходит ИЗ ЕДИНОЙ ТОЧКИ ПРАВДЫ (database_path <- Support\Paths).
     */
    private function applySchema(): string
    {
        if ($this->db === null) {
            return 'пропущено (модуль Database недоступен)';
        }

        try {
            if (!$this->db->ping()) {
                return 'пропущено (СУБД недоступна — включите драйвер и повторите миграцию позже)';
            }

            // Фиксированное имя дампа; папка — только из Paths, без dirname/конкатенаций корня.
            $dumpFile = rtrim($this->databasePath, '/') . '/db.sql';
            if (!$this->fs->isFile($dumpFile)) {
                return "пропущено (не найден {$dumpFile})";
            }

            // Идемпотентность по ПОЛНОМУ набору таблиц дампа, а не по одной «счастливой»
            // таблице: ранее схема могла быть развёрнута частично (например, упал один
            // оператор), и проверка по 'pages' давала ложное «уже развёрнута».
            $tables     = $this->dumpTables($this->fs->read($dumpFile));
            $missing    = array_values(array_filter(
                $tables,
                fn (string $t): bool => !$this->db->tableExists($t),
            ));

            if ($tables !== [] && $missing === []) {
                return 'уже развёрнута (' . count($tables) . ' таблиц схемы обнаружено, пропущено)';
            }

            $statements = $this->db->migrate($this->fs->read($dumpFile));

            $this->logger->info("Схема БД развёрнута: {$statements} операторов из db.sql", [
                'channel' => 'Installer',
            ]);

            // Автоприменение версий схемы (модуль Migration) сразу после дампа —
            // деградирует тихо, если миграций/БД нет (migratePending не бросает).
            $appliedMigrations = $this->migrator?->migratePending() ?? [];
            $migrationNote = $appliedMigrations === []
                ? ''
                : ', применено миграций: ' . count($appliedMigrations);

            return "развёрнута ({$statements} операторов из db.sql{$migrationNote})";
        } catch (Throwable $e) {
            $this->logger->error('Schema-шаг установки не выполнен: ' . $e->getMessage(), [
                'channel' => 'Installer',
            ]);

            // Ошибка схемы — это провал установки (не «предупреждение»): без таблиц
            // движок неработоспособен, флаг .installed будет снят вызывающим.
            return 'предупреждение: ' . $e->getMessage();
        }
    }

    /**
     * Database-шаг установки: создаёт отсутствующую БД на активном драйвере.
     * Возвращает текст для successPage; недоступная СУБД — предупреждение, не провал.
     */
    private function tryCreateDatabase(): string
    {
        if ($this->db === null) {
            return 'модуль Database недоступен (пропущено)';
        }

        $driver   = $this->db->driver();
        $database = (string) $this->config->get("database.connections.{$driver}.database", '');

        try {
            if ($this->db->ping()) {
                return "соединение установлено ({$driver})";
            }

            // mysql/pgsql: коннект к серверу без выбора БД + CREATE DATABASE IF NOT EXISTS.
            // Имя БД экранируется backticks-ами и валидируется regex (защита от инъекции в DDL).
            if ($driver === 'mysql' || $driver === 'pgsql') {
                if (!preg_match('/^[A-Za-z0-9_]+$/', $database)) {
                    return "имя БД '{$database}' недопустимо (только буквы/цифры/_), создание пропущено";
                }

                $serverDsn = $driver === 'mysql'
                    ? sprintf(
                        'mysql:host=%s;port=%d;charset=utf8mb4',
                        (string) $this->config->get("database.connections.{$driver}.host", '127.0.0.1'),
                        (int) $this->config->get("database.connections.{$driver}.port", 3306),
                    )
                    : sprintf(
                        'pgsql:host=%s;port=%d',
                        (string) $this->config->get("database.connections.{$driver}.host", '127.0.0.1'),
                        (int) $this->config->get("database.connections.{$driver}.port", 5432),
                    );

                $root = new \PDO(
                    $serverDsn,
                    (string) $this->config->get("database.connections.{$driver}.username", ''),
                    (string) $this->config->get("database.connections.{$driver}.password", ''),
                    [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
                );

                $quoted = $driver === 'mysql' ? '`' . $database . '`' : '"' . $database . '"';
                $charset = $driver === 'mysql' ? ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' : '';
                $root->exec("CREATE DATABASE IF NOT EXISTS {$quoted}" . $charset);

                return "база '{$database}' создана/существует ({$driver})"
                    . ($this->db->ping() ? ', соединение подтверждено' : '');
            }

            if ($driver === 'sqlite') {
                // Файл создаётся самим PDO при первом коннекте — достаточно ping().
                return $this->db->ping() ? "файл БД создан ({$driver})" : "sqlite недоступен (пропущено)";
            }

            return "драйвер '{$driver}' — создание пропущено";
        } catch (Throwable $e) {
            $this->logger->warning('Database-шаг установки не выполнен: ' . $e->getMessage(), [
                'channel' => 'Installer',
            ]);

            return 'предупреждение: ' . $e->getMessage();
        }
    }

    private function ensureWritableDir(string $dir): bool
    {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return false;
        }
        return is_writable($dir);
    }
}
