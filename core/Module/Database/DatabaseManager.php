<?php
declare(strict_types=1);

namespace App\Core\Module\Database;

use App\Core\Module\Config\Contracts\ConfigRepositoryInterface;
use App\Core\Module\Database\Contracts\DatabaseInterface;
use App\Core\Module\Database\Internal\Connectors\PdoConnector;
use App\Core\Module\Database\Internal\QueryBuilders\QueryBuilder;
use App\Core\Module\Database\Internal\Schema\SqlFileRunner;
use App\Core\System\Container\Contracts\ContainerInterface;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Фасад модуля Database (менеджер соединений поверх PDO).
 *
 * Зависимости (только через контракты/ключи контейнера, п.3/п.7):
 *   ConfigRepositoryInterface — database.driver и credentials;
 *   LoggerInterface (канал 'Database') — ошибки соединений;
 *   'storage_path' — для sqlite-файла (единая точка правды Paths).
 *
 * Соединение ленивое: PDO создаётся при первом запросе — движок стартует,
 * даже если СУБД недоступна (Doctor честно сообщит ping=false).
 */
final class DatabaseManager implements DatabaseInterface
{
    private ?PDO $pdo = null;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ConfigRepositoryInterface $config,
    ) {
    }

    // ==================== DatabaseInterface ====================

    public function beginTransaction(): void
    {
        if ($this->pdo()->inTransaction()) {
            throw new RuntimeException('Модуль [Database]: транзакция уже открыта.');
        }
        $this->pdo()->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo()->commit();
    }

    public function rollBack(): void
    {
        $this->pdo()->rollBack();
    }

    /** @param array<int|string,mixed> $bindings */
    public function run(string $sql, array $bindings = []): int
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($bindings);

        return $stmt->rowCount();
    }

    /** @return list<array<string,mixed>> */
    public function select(string $sql, array $bindings = []): array
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($bindings);

        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    /** @return array<string,mixed>|null */
    public function selectOne(string $sql, array $bindings = []): ?array
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($bindings);
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function query(string $table): object
    {
        return new QueryBuilder($this, $table);
    }

    /**
     * Прогон SQL-дампа (например database/db.sql) через активное PDO-соединение.
     * Реализация DatabaseInterface::migrate(). Идемпотентность обеспечивает вызывающий
     * (Installer проверяет наличие таблиц перед прогоном).
     *
     * @return int количество выполненных операторов
     */
    public function migrate(string $sql): int
    {
        // Дампы из phpMyAdmin содержат MySQL-директивы (SET SQL_MODE и т.п.) —
        // исполнитель пропускает их для не-mysql драйверов (переносимость схемы).
        return count((new SqlFileRunner())->run($this->pdo(), $sql, $this->driver()));
    }

    /** Существует ли таблица в активной БД (information_schema/sqlite_master). */
    public function tableExists(string $table): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            throw new RuntimeException("Модуль [Database]: недопустимое имя таблицы '{$table}'.");
        }

        // Для sqlite: если файл БД пуст (0 байт) — таблиц заведомо нет.
        // Иначе PDO создаёт in-memory соединение и даёт ложные результаты.
        if ($this->driver() === 'sqlite') {
            $file = $this->sqliteFile();
            if ($file !== null && is_file($file) && filesize($file) === 0) {
                return false;
            }
        }

        return match ($this->driver()) {
            'mysql'  => (bool) $this->selectOne(
                'SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
                [$table],
            ),
            'pgsql'  => (bool) $this->selectOne('SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_name = ?', [$table]),
            'sqlite' => (bool) $this->selectOne('SELECT COUNT(*) AS c FROM sqlite_master WHERE type = ? AND name = ?', ['table', $table]),
            default  => false,
        };
    }

    public function driver(): string
    {
        return $this->activeDriver();
    }

    public function ping(): bool
    {
        try {
            return $this->pdo()->query('SELECT 1')->fetchColumn() !== false;
        } catch (Throwable) {
            return false;
        }
    }

    // ==================== Internal ====================

    /** Абсолютный путь sqlite-файла из конфига (резолв через storage_path — п.7). */
    private function sqliteFile(): ?string
    {
        $path = (string) $this->config->get('database.connections.sqlite.database', '');

        if ($path === '') {
            return null;
        }

        // Относительные пути резолвим тем же способом, что и PdoConnector:
        // от корня проекта (в конфигурации это 'storage/database.sqlite').
        // Прежняя логика («относительный = внутри storage/») ломала симметрию:
        // PDO открывал один файл, а tableExists смотрел filesize другого —
        // реестр миграций ложно считался несозданным.
        if (!str_starts_with($path, '/')) {
            $path = rtrim(BASE_PATH, '/') . '/' . $path;
        }

        return $path;
    }

    private function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $driver = $this->activeDriver();

        if (!$this->config->has("database.connections.{$driver}")) {
            throw new RuntimeException("Модуль [Database]: неизвестный драйвер '{$driver}'.");
        }

        /** @var array<string,mixed> $connection */
        $connection = $this->config->get("database.connections.{$driver}", []);
        $connection['driver'] ??= $driver;

        if (!(bool) ($connection['enabled'] ?? false)) {
            throw new RuntimeException(
                "Модуль [Database]: драйвер '{$driver}' выключен (enabled=false)."
                . ' Включите его в config/database.php или DB_' . strtoupper($driver) . '_ENABLED=true в .env.'
            );
        }

        $common = [
            'emulate_prep' => (bool) $this->config->get('database.emulate_prep', false),
            'timeout'      => (int) $this->config->get('database.timeout', 5),
        ];

        $storagePath = $this->container->has('storage_path')
            ? (string) $this->container->get('storage_path')
            : null;

        $this->pdo = (new PdoConnector())->make([...$connection, ...$common], $storagePath);

        return $this->pdo;
    }

    private function activeDriver(): string
    {
        return strtolower((string) $this->config->get('database.driver', 'mysql'));
    }
}
