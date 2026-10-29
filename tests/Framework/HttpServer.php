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
     */
    public static function boot(string $root, array $env, bool $installed = true): self
    {
        $server = new self($root);
        $server->installFixture($env, $installed);
        $server->start();

        return $server;
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
        exec('cp -R ' . escapeshellarg($this->root) . '/. ' . escapeshellarg($this->dir) . ' 2>/dev/null');
        if (!is_dir($this->dir . '/public')) {
            throw new \RuntimeException('Не удалось подготовить HTTP-стенд из ' . $this->root);
        }

        $lines = [];
        foreach ($env as $key => $value) {
            $lines[] = "{$key}={$value}";
        }
        file_put_contents($this->dir . '/.env', implode("\n", $lines) . "\n");

        if (!is_dir($this->dir . '/storage')) {
            mkdir($this->dir . '/storage', 0775, true);
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
