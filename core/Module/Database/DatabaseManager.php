<?php
declare(strict_types=1);

namespace App\Core\Module\Database;

use App\Core\Module\Config\Contracts\ConfigRepositoryInterface;
use App\Core\Module\Database\Contracts\DatabaseInterface;
use App\Core\Module\Database\Internal\Connectors\PdoConnector;
use App\Core\Module\Database\Internal\QueryBuilders\QueryBuilder;
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
