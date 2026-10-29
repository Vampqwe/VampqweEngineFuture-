<?php
declare(strict_types=1);

namespace App\Core\Module\Database\Internal\Connectors;

use PDO;
use RuntimeException;

/**
 * Внутренний PDO-коннектор (сборка DSN из конфигурации database.*).
 * ЗАПРЕЩЕНО использовать напрямую извне модуля (п.2 Конституции).
 *
 * Безопасность: соединения всегда с нативными prepared statements
 * (ATTR_EMULATE_PREPARES=false), ERRMODE_EXCEPTION, fetch-ассоциативный.
 */
final class PdoConnector
{
    /** @param array<string,mixed> $config элемент database.connections.<driver> + общие опции */
    public function make(array $config, ?string $storagePath = null): PDO
    {
        $driver = strtolower((string) ($config['driver'] ?? ''));

        $dsn = match ($driver) {
            'mysql'  => $this->dsnHosted($config, 'mysql'),
            'pgsql'  => $this->dsnHosted($config, 'pgsql'),
            'sqlite' => $this->dsnSqlite($config, $storagePath),
            default  => throw new RuntimeException(
                "Модуль [Database]: неизвестный драйвер '{$driver}' (доступно: mysql, pgsql, sqlite)."
            ),
        };

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Нативные подготовленные выражения — основа безопасности запросов.
            PDO::ATTR_EMULATE_PREPARES   => (bool) ($config['emulate_prep'] ?? false),
            PDO::ATTR_PERSISTENT         => false,
        ];

        if ($driver === 'mysql') {
            // PDO::MYSQL_ATTR_INIT_COMMAND существует только если расширение
            // pdo_mysql скомпилировано — иначе это фатальная ошибка (не найден
            // константа). Защита: добавляем опцию только при наличии расширения.
            if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
                $options[PDO::MYSQL_ATTR_INIT_COMMAND] = 'SET NAMES '
                    . (string) ($config['charset'] ?? 'utf8mb4');
            }
        }

        if (isset($config['timeout']) && $driver !== 'sqlite') {
            // Явный таймаут коннекта вместо бесконечного зависания.
            $options[PDO::ATTR_TIMEOUT] = (int) $config['timeout'];
        }

        try {
            return new PDO(
                $dsn,
                $driver === 'sqlite' ? null : (string) ($config['username'] ?? ''),
                $driver === 'sqlite' ? null : (string) ($config['password'] ?? ''),
                $options,
            );
        } catch (\PDOException $e) {
            throw new RuntimeException(
                'Модуль [Database]: не удалось подключиться к ' . $driver . ': ' . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    /** @param array<string,mixed> $config */
    private function dsnHosted(array $config, string $driver): string
    {
        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (int) ($config['port'] ?? ($driver === 'mysql' ? 3306 : 5432));
        $name = (string) ($config['database'] ?? '');

        if ($name === '') {
            throw new RuntimeException("Модуль [Database]: для драйвера {$driver} не задано имя БД (DB_NAME).");
        }

        return $driver === 'mysql'
            ? sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, (string) ($config['charset'] ?? 'utf8mb4'))
            : sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $name);
    }

    /** @param array<string,mixed> $config */
    private function dsnSqlite(array $config, ?string $storagePath): string
    {
        $path = (string) ($config['database'] ?? 'database.sqlite');

        if (!str_starts_with($path, '/') && $storagePath !== null) {
            // Относительный путь резолвим ЕДИНЫМ ИСТОЧНИКОМ путей — storage_path из контейнера (п.7).
            $path = rtrim($storagePath, '/') . '/' . $path;
        }

        return 'sqlite:' . $path;
    }
}
