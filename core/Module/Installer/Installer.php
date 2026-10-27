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

    public function install(array $options = []): bool
    {
        $flagFile = $this->storagePath . '/' . self::FLAG_INSTALLING;
        $this->fs->createFile($flagFile);

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

            // Database-шаг: создаём БД, если активный драйвер включён и доступен.
            // Недоступная СУБД НЕ валит установку — ошибка уходит в лог канала Installer.
            $dbSummary = $this->tryCreateDatabase();

            $this->logger->info('Установка завершена успешно', ['channel' => 'Installer']);

            return true;
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

    public function requirementsPage(array $checks): string
    {
        $rows = '';
        foreach ($checks as $label => $ok) {
            $cls  = $ok ? 'ok' : 'fail';
            $icon = $ok ? '&#10004;' : '&#10008;';
            $rows .= '<tr><td>' . InstallerView::esc((string) $label) . '</td>'
                   . '<td class="' . $cls . '">' . $icon . ' ' . ($ok ? 'OK' : 'НЕ ОБНАРУЖЕНО') . '</td></tr>';
        }

        $allOk = !in_array(false, $checks, true);
        $cta   = $allOk
            ? '<form method="post" action="/install/settings"><button>Перейти к настройкам &rarr;</button></form>'
            : '<div class="errbox">Устраните проблемы выше и обновите страницу.</div>';

        $body = '<div class="sub">Проверка окружения перед установкой движка.</div>'
              . '<h2>Требования</h2><table>' . $rows . '</table>' . $cta;

        return InstallerView::page('Установка VampqweEngineFuture', $body, ['step' => 1]);
    }

    public function settingsForm(array $errors = []): string
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

    public function successPage(array $summary): string
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

    public function failurePage(string $reason): string
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
