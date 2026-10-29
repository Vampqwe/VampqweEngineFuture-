<?php
declare(strict_types=1);

namespace App\Core\Module\Admin\Internal;

use App\Core\Enums\AppState;
use App\Core\Module\Database\Contracts\DatabaseInterface;
use App\Core\Module\Migration\Contracts\MigratorInterface;
use App\Core\System\Container\Contracts\ContainerInterface;
use App\Core\System\Support\ModuleBridge;

/**
 * Агрегатор информации о движке для главной страницы админ-панели (Dashboard v2).
 *
 * Собирает «живое» состояние системы: версия, PHP-окружение, состояние
 * установки, идентичность сайта, БД и миграции, размеры кэшей, список
 * рабочих модулей и последние ошибки из логов. Никаких секретов (APP_KEY,
 * пароли БД) в выдачу не попадает — только имена драйверов (п.8 Конституции).
 *
 * Зависимости опциональны: при недоступном модуле соответствующий блок
 * получает пустые значения и UI рисует empty-state — честная деградация (п.9).
 */
final class EngineInfoService
{
    private readonly ModuleBridge $bridge;

    public function __construct(
        private readonly ContainerInterface $container,
    ) {
        // Доступ к модулям — через мост ядра (инвариант R6b: имена классов
        // модулей живут только в ModuleBridge/контрактах, здесь — геттеры).
        $this->bridge = new ModuleBridge($container);
    }

    /**
     * Полный снимок состояния движка для Dashboard v2.
     *
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        return [
            'engine'   => $this->engineBlock(),
            'php'      => $this->phpBlock(),
            'state'    => $this->stateBlock(),
            'site'     => $this->siteBlock(),
            'database' => $this->databaseBlock(),
            'cache'    => $this->cacheBlock(),
            'modules'  => $this->modulesBlock(),
            'logs'     => $this->logTailBlock(),
        ];
    }

    /** @return array{name:string,version:string,mode:string} */
    private function engineBlock(): array
    {
        $debug = (bool) $this->bridge->configValue('app.debug', false);
        return [
            'name'    => (string) $this->bridge->configValue('app.name', 'VampqweEngineFuture'),
            'version' => (string) $this->bridge->configValue('app.version', '0.1.0'),
            'mode'    => $debug ? 'Debug' : 'Production',
        ];
    }

    /**
     * PHP-окружение: версия, SAPI, лимит памяти, opcache, ключевые расширения.
     *
     * @return array<string,mixed>
     */
    private function phpBlock(): array
    {
        $memLimit = (string) ini_get('memory_limit');
        $opcache  = 'выключен';
        if (function_exists('opcache_get_status')) {
            $st = @opcache_get_status(false);
            $opcache = is_array($st) && ($st['enabled'] ?? false) ? 'включён' : 'выключен';
        }
        $extensions = [];
        foreach (['pdo', 'json', 'mbstring', 'openssl', 'curl', 'zip'] as $e) {
            $extensions[] = ['name' => $e, 'loaded' => extension_loaded($e)];
        }
        return [
            'version'      => PHP_VERSION,
            'sapi'         => PHP_SAPI,
            'memory_limit' => $memLimit === '' ? '—' : $memLimit,
            'opcache'      => $opcache,
            'extensions'   => $extensions,
        ];
    }

    /** @return array{state:string,label:string,color:string} */
    private function stateBlock(): array
    {
        $storagePath = $this->bridge->path('storage_path');
        $state       = AppState::detect($storagePath);
        [$label, $color] = match ($state) {
            AppState::Running      => ['Запущен', 'green'],
            AppState::NotInstalled => ['Не установлен', 'yellow'],
            AppState::Installing   => ['Установка…', 'blue'],
            AppState::Upgrading    => ['Обновление…', 'blue'],
            AppState::Maintenance  => ['Техническое обслуживание', 'yellow'],
            AppState::Error        => ['Ошибка', 'red'],
        };
        return ['state' => $state->value, 'label' => $label, 'color' => $color];
    }

    /** @return array{domain:string,url:string,theme:string,strict:string} */
    private function siteBlock(): array
    {
        return [
            'domain' => $this->bridge->siteDomain(),
            'url'    => trim((string) $this->bridge->configValue('app.site_url', '')),
            'theme'  => $this->bridge->defaultTheme(),
            'strict' => $this->bridge->siteStrictHost() ? 'строгий хост' : 'мягкий режим',
        ];
    }

    /**
     * Блок БД: драйвер, версия сервера, таблицы со счётчиками, статус миграций.
     * Пустая/недоступная база даёт connected=false и пустые списки (п.9).
     *
     * @return array<string,mixed>
     */
    private function databaseBlock(): array
    {
        $block = [
            'driver'    => (string) $this->bridge->configValue('database.driver', '—'),
            'server'    => '',
            'connected' => false,
            'tables'    => [],
            'migration' => null,
        ];

        $db = $this->bridge->database();
        if (!$db instanceof DatabaseInterface) {
            return $block;
        }

        try {
            $row               = $db->selectOne('SELECT VERSION() AS v', []);
            $block['server']    = (string) ($row['v'] ?? '');
            $block['connected'] = true;
        } catch (\Throwable) {
            return $block; // соединение есть, но запрос упал — честно показываем disconnected
        }

        // Таблицы схемы движка + счётчики строк (только существующие: база
        // сразу после установки без данных даёт нули, а не ошибку).
        foreach ([
            'pages'           => 'Страницы',
            'users'           => 'Пользователи',
            'auth_users'      => 'Аккаунты',
            'redirects'       => 'Редиректы',
            'engine_sites'    => 'Сайты',
            'engine_settings' => 'Настройки',
            'tasks'           => 'Задачи',
            'menu_items'      => 'Меню',
        ] as $table => $label) {
            if (!$db->tableExists($table)) {
                continue;
            }
            $cnt              = $db->selectOne('SELECT COUNT(*) AS c FROM `' . $table . '`', []);
            $block['tables'][] = ['table' => $table, 'label' => $label, 'rows' => (int) ($cnt['c'] ?? 0)];
        }

        $migrator = $this->container->tryGet(MigratorInterface::class);
        if ($migrator instanceof MigratorInterface) {
            try {
                $status             = $migrator->status();
                $block['migration'] = [
                    'applied' => count($status['applied'] ?? []),
                    'pending' => count($status['pending'] ?? []),
                ];
            } catch (\Throwable) {
                $block['migration'] = null; // реестр ещё не создан — empty-state
            }
        }

        return $block;
    }

    /**
     * Кэш шаблонов: число скомпилированных файлов и их суммарный размер.
     * Каталог — тот же, что у TemplateServiceProvider ('cache_path' + /templates).
     *
     * @return array{files:int,size:string,path:string}
     */
    private function cacheBlock(): array
    {
        $cacheRoot = $this->bridge->path('cache_path');
        $dir       = $cacheRoot !== '' ? rtrim($cacheRoot, '/') . '/templates' : '';
        $files     = 0;
        $bytes     = 0;
        if ($dir !== '' && is_dir($dir)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $f) {
                if ($f->isFile()) {
                    $files++;
                    $bytes += $f->getSize();
                }
            }
        }
        return [
            'files' => $files,
            'size'  => self::humanBytes($bytes),
            'path'  => $dir,
        ];
    }

    /**
     * Рабочие модули по манифесту config/modules.php (единый источник истины
     * о подключении — п.5). Модуль «рабочий», если его провайдер реально
     * существует (autoload найден) — этого достаточно, чтобы App его
     * зарегистрировал при boot.
     *
     * @return array<int,array{name:string,critical:bool,ok:bool}>
     */
    private function modulesBlock(): array
    {
        $manifestPath = $this->bridge->path('config_path') . '/modules.php';
        $manifest     = is_file($manifestPath) ? (array) require $manifestPath : [];
        $out          = [];
        foreach ($manifest as $name => $meta) {
            $provider = (string) (is_array($meta) ? ($meta['provider'] ?? '') : (string) $meta);
            $critical = is_array($meta) ? (bool) ($meta['critical'] ?? false) : false;
            $out[]    = [
                'name'     => (string) $name,
                'critical' => $critical,
                'ok'       => $provider !== '' && class_exists($provider),
            ];
        }
        usort($out, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
        return $out;
    }

    /**
     * Хвост самого свежего лог-файла: до 5 строк ERROR/CRITICAL/WARNING/ALERT.
     * Логи лежат в logs/<дата>/<канал>.log (см. Paths::MAP 'logs').
     *
     * @return array{file:string,lines:array<int,string>}
     */
    private function logTailBlock(): array
    {
        $dir = $this->bridge->path('logs_path');
        if ($dir === '' || !is_dir($dir)) {
            return ['file' => '', 'lines' => []];
        }
        $found = [];
        $stack = [$dir];
        while ($stack !== []) {
            $cur = (string) array_pop($stack);
            foreach ((array) scandir($cur) as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $full = $cur . '/' . (string) $entry;
                if (is_dir($full)) {
                    $stack[] = $full;
                } elseif (str_ends_with((string) $entry, '.log')) {
                    $found[$full] = (int) @filemtime($full);
                }
            }
        }
        if ($found === []) {
            return ['file' => '', 'lines' => []];
        }
        arsort($found);
        $latest = (string) array_key_first($found);
        $lines  = [];
        $handle = @fopen($latest, 'r');
        if ($handle !== false) {
            while (($line = fgets($handle)) !== false) {
                if (preg_match('/ERROR|CRITICAL|WARNING|ALERT/i', (string) $line) === 1) {
                    $lines[] = mb_substr(trim((string) $line), 0, 300);
                    if (count($lines) >= 5) {
                        break;
                    }
                }
            }
            fclose($handle);
        }
        return ['file' => basename($latest), 'lines' => array_reverse($lines)];
    }

    private static function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i     = 0;
        $size  = (float) $bytes;
        while ($size >= 1024.0 && $i < count($units) - 1) {
            $size /= 1024.0;
            $i++;
        }
        return round($size, 1) . ' ' . $units[$i];
    }
}
