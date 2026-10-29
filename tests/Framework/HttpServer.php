<?php
declare(strict_types=1);

namespace Tests\Framework;

/**
 * Хелпер интеграционных HTTP-тестов: поднимает встроенный сервер PHP
 * (php -S 127.0.0.1:<port> -t public public/router.php) на временном
 * стенде и дёргает реальные URL с реальными Host-заголовками.
 *
 * Зачем: матрица идентичности сайта (strict / мягкий / legacy-режимы,
 * см. BASE_URL_SITE в .env.example) раньше проверялась только ручным
 * смоуком. Этот класс превращает ручной контур в автоматический CI-контур:
 * запрос проходит весь HTTP-конвейер ядра (Kernel -> роутинг -> резолвер),
 * а не только юнит-логику SiteIdentity.
 *
 * Изоляция: каждый стенд живёт в sys_get_temp_dir() со своим .env,
 * sqlite-базой и storage/.installed — параллельные запуски не мешают друг
 * другу и не трогают рабочий каталог проекта. Сервер убивается в stop().
 */
final class HttpServer
{
    private string $dir;
    /** @var resource|null */
    private $process = null;
    /** @var array<int,resource> */
    private array $pipes = [];
    private int $port = 0;

    private function __construct(private readonly string $root)
    {
        $this->dir = sys_get_temp_dir() . '/engine_http_' . bin2hex(random_bytes(4));
    }

    /**
     * Создаёт стенд и запускает сервер.
     *
     * @param array<string,string> $env       содержимое .env для инсталляции
     * @param bool                 $installed ставить ли флаг storage/.installed
     * @param bool                 $migrate   развернуть ли схему из database/*.sql
     *                                        (создать sqlite-файл с таблицами) до
     *                                        старта сервера. Нужен тестам на
     *                                        «установленный движок»: миграция в
     *                                        ядре выполняется ТОЛЬКО установщиком,
     *                                        поэтому хелпер применяет тот же
     *                                        единственный путь — SqlSchemaMigrator.
     */
    public static function boot(string $root, array $env, bool $installed = true, bool $migrate = false): self
    {
        $server = new self($root);
        $server->installFixture($env, $installed);
        if ($migrate) {
            $server->applySchema($env);
        }
        // Снимем снимок каталога ДО старта сервера: php -S пишет stderr в
        // неблокируемые пайпы с буфером ~64 КБ — после переполнения буфера
        // сервер может блокироваться, и читать пайпы «в момент падения»
        // уже поздно. Фиксация состояния сразу даёт честную диагностику.
        try {
            $server->start();
        } catch (\Throwable $e) {
            $diag = [];
            foreach (['storage/logs' => 'логи ядра', 'view/app/engineStart' => 'шаблоны темы'] as $rel => $label) {
                $files = glob($server->dir . '/' . $rel . '/*') ?: [];
                $diag[] = $label . ': ' . implode(', ', array_map('basename', $files));
            }
            $dbFile = $server->dir . '/storage/' . ($env['DB_DATABASE_PATH'] ?? 'engine.sqlite');
            $diag[] = is_file($dbFile)
                ? 'БД: ' . $dbFile . ' (' . filesize($dbFile) . ' байт)'
                : 'БД: файл НЕ найден по пути ' . $dbFile;
            throw new \RuntimeException(
                $e->getMessage() . "\n--- Диагностика стенда ---\n" . implode("\n", $diag),
            );
        }

        return $server;
    }

    /**
     * Разворачивает схему БД стенда тем же единственным путём, что и установка:
     * модуль Migration (SqlSchemaMigrator) поверх Database-модуля. Контейнер
     * живёт только внутри этого вызова — серверу передаётся уже готовый файл.
     *
     * @param array<string,string> $env содержимое .env стенда
     */
    private function applySchema(array $env): void
    {
        $driver = strtolower($env['DB_DRIVER'] ?? 'sqlite');
        if ($driver !== 'sqlite') {
            throw new \RuntimeException('HttpServer::boot(migrate: true) поддерживает только sqlite-стенды.');
        }

        // Собираем тот же единственный путь, что и Installer: Config -> Database
        // -> Migration (SqlSchemaMigrator). Кириллица в путях temp-каталога может
        // ломать escapeshellarg у php -S, поэтому прогон — в дочернем процессе с
        // ASCII-cwd; сервер получает уже готовый файл БД.
        $script = <<<'PHP'
<?php
declare(strict_types=1);

$root = getenv('ENGINE_STAND_ROOT');
require $root . '/vendor/autoload.php';

use App\Core\Module\Config\Contracts\ConfigRepositoryInterface;
use App\Core\Module\Config\Internal\Parsers\PhpArrayParser;
use App\Core\Module\Config\ConfigRepository;
use App\Core\Module\Database\Contracts\DatabaseInterface;
use App\Core\Module\Database\DatabaseManager;
use App\Core\Module\FileSystem\FileSystem;
use App\Core\Module\Hook\Hook;
use App\Core\Module\Logger\Logger;
use App\Core\Module\Migration\Internal\SqlSchemaMigrator;
use App\Core\Module\TimeDate\TimeDate;
use App\Core\System\Container\Container;
use App\Core\System\Support\EnvLoader;

$fs = new FileSystem();
EnvLoader::load($root . '/.env');

$container = new Container();
$container->instance('storage_path', $root . '/storage');

$config = new ConfigRepository(new PhpArrayParser(), $root . '/config');
$container->instance(ConfigRepositoryInterface::class, $config);

$db     = new DatabaseManager($container, $config);
$logger = new Logger($fs, $root . '/storage/logs');

$migrator = new SqlSchemaMigrator($db, $fs, new TimeDate(), $logger, new Hook(), $root . '/database');

if (!$migrator->hasSchemaSource()) {
    fwrite(STDERR, "В каталоге database/ нет дампа со CREATE TABLE — нечего разворачивать.\n");
    exit(1);
}
$migrator->run();
PHP;

        $scriptPath = $this->dir . '/_migrate_stand.php';
        file_put_contents($scriptPath, $script);

        $cmd = sprintf(
            'cd %s && ENGINE_STAND_ROOT=%s %s _migrate_stand.php',
            escapeshellarg($this->dir),
            escapeshellarg($this->dir),
            PHP_BINARY,
        );
        exec($cmd . ' 2>&1', $output, $exitCode);

        @unlink($scriptPath);

        if ($exitCode !== 0) {
            throw new \RuntimeException(
                'Не удалось развернуть схему sqlite-стенда из database/*.sql: ' . implode("\n", $output),
            );
        }
    }

    public function port(): int
    {
        return $this->port;
    }

    /** Каталог стенда (для проверки артефактов установки: .env, storage/*). */
    public function dir(): string
    {
        return $this->dir;
    }

    /** Файл стенда существует? (напр. 'storage/.installed' или '.env'). */
    public function has(string $relative): bool
    {
        return is_file($this->dir . '/' . ltrim($relative, '/'));
    }

    /** Содержимое файла стенда ('' если отсутствует). */
    public function readFile(string $relative): string
    {
        $path = $this->dir . '/' . ltrim($relative, '/');

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * GET-запрос к стенду. Возвращает ['status' => int, 'body' => string].
     * Пишем/читаем сокетом напрямую: file_get_contents с потоком не даёт
     * надёжного доступа к статусу ответа, а нам нужны и 4xx (strict-режим).
     */
    public function get(string $path, string $host): array
    {
        return $this->send('GET', $path, $host, null);
    }

    /**
     * POST-запрос с form-urlencoded телом (нужен для прогона установщика:
     * POST /install/run — тот самый контур, где была регрессия 503 из-за
     * рекурсивной блокировки AppState::Installing).
     *
     * @param array<string,string> $fields
     * @return array{status:int,body:string}
     */
    public function post(string $path, string $host, array $fields): array
    {
        return $this->send('POST', $path, $host, http_build_query($fields));
    }

    /**
     * @param string|null $body тело запроса (для POST)
     * @return array{status:int,body:string}
     */
    private function send(string $method, string $path, string $host, ?string $body): array
    {
        $sock = @stream_socket_client(
            'tcp://127.0.0.1:' . $this->port,
            $errno,
            $errstr,
            5.0,
        );
        if ($sock === false) {
            throw new \RuntimeException("Соединение со стендом не установлено: {$errstr}");
        }
        stream_set_timeout($sock, 10);

        $headers = "{$method} {$path} HTTP/1.1\r\n"
            . "Host: {$host}\r\n"
            . "Connection: close\r\n";
        if ($body !== null) {
            $headers .= "Content-Type: application/x-www-form-urlencoded\r\n"
                . 'Content-Length: ' . strlen($body) . "\r\n";
        }
        $headers .= "\r\n";
        fwrite($sock, $headers . ($body ?? ''));

        $raw = '';
        while (!feof($sock)) {
            $chunk = fread($sock, 65_536);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $raw .= $chunk;
        }
        fclose($sock);

        [$head, $body] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
        $status = 0;
        if (preg_match('#^HTTP/\d(?:\.\d)? (\d{3})#', $head, $m) === 1) {
            $status = (int) $m[1];
        }

        return ['status' => $status, 'body' => (string) $body];
    }

    private function installFixture(array $env, bool $installed): void
    {
        // Копия точки входа и ресурсов проекта в изолированный стенд.
        // Исключаем рабочие артефакты исходного дерева (.env, содержимое
        // storage/) — иначе cp создаёт уже существующие каталоги и шумит
        // mkdir()-варнингами, а стенд наследует чужое состояние.
        exec(
            'rsync -a --exclude=.env --exclude=storage/ ' . escapeshellarg($this->root) . '/. '
            . escapeshellarg($this->dir) . ' 2>/dev/null || cp -R '
            . escapeshellarg($this->root) . '/. ' . escapeshellarg($this->dir) . ' 2>/dev/null'
        );
        if (!is_dir($this->dir . '/public')) {
            throw new \RuntimeException('Не удалось подготовить HTTP-стенд из ' . $this->root);
        }

        $lines = [];
        foreach ($env as $key => $value) {
            $lines[] = "{$key}={$value}";
        }
        file_put_contents($this->dir . '/.env', implode("\n", $lines) . "\n");

        // Пустой каталог storage стенда: флаги/БД/логи живут только здесь.
        @mkdir($this->dir . '/storage', 0775, true);
        foreach (['logs', 'cache', 'sessions', 'database'] as $sub) {
            @mkdir($this->dir . '/storage/' . $sub, 0775, true);
        }
        if ($installed) {
            file_put_contents($this->dir . '/storage/.installed', '1');
        }
    }

    private function start(): void
    {
        $this->port = $this->findFreePort();
        $cmd = sprintf(
            '%s -S 127.0.0.1:%d -t %s %s/router.php',
            PHP_BINARY,
            $this->port,
            escapeshellarg($this->dir . '/public'),
            escapeshellarg($this->dir . '/public'),
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes, $this->dir);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Не удалось запустить php -S для HTTP-теста.');
        }
        $this->process = $proc;
        $this->pipes   = $pipes;
        foreach ($this->pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }

        $this->waitReady();
    }

    /** Ждём, пока сервер начнёт принимать соединения (макс. ~5 сек). */
    private function waitReady(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $sock = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.1);
            if ($sock !== false) {
                fclose($sock);

                return;
            }

            usleep(50_000);
        }

        $log = '';
        foreach ($this->pipes as $pipe) {
            $log .= (string) stream_get_contents($pipe);
        }
        $this->stop();
        throw new \RuntimeException("Сервер HTTP-стенда не поднялся. Лог:\n{$log}");
    }

    private function findFreePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($sock === false) {
            throw new \RuntimeException("Нет свободных портов: {$errstr}");
        }
        $name = (string) stream_socket_get_name($sock, false);
        fclose($sock);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    public function stop(): void
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $this->pipes = [];

        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }

        if (is_dir($this->dir)) {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
    }

    public function __destruct()
    {
        $this->stop();
    }
}
