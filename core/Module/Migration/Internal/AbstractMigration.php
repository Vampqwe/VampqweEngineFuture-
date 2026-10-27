<?php
declare(strict_types=1);

namespace App\Core\Module\Migration\Internal;

use App\Core\Module\Database\Contracts\DatabaseInterface;
use App\Core\Module\Migration\Contracts\MigrationInterface;

/**
 * Базовый класс одной миграции (внутренняя кухня модуля Migration — п.2).
 *
 * Файлы в migrations/ наследуют этот класс и реализуют up()/down() через
 * helpers sql()/tableExists(), получая доступ к БД ИСКЛЮЧИТЕЛЬНО через
 * контракт DatabaseInterface (никакого new PDO внутри миграций!).
 */
abstract class AbstractMigration implements MigrationInterface
{
    public function __construct(
        protected readonly DatabaseInterface $db,
        private readonly string $id,
    ) {
    }

    final public function id(): string
    {
        return $this->id;
    }

    /** Выполнить SQL-оператор через Database (prepared-safe). */
    final protected function sql(string $statement): int
    {
        return $this->db->run($statement);
    }

    /** Идемпотентный хелпер: добавить индекс, если его ещё нет (sqlite + mysql). */
    final protected function addIndexIfMissing(string $index, string $table, string $columns): int
    {
        // DDL-идентификаторы НЕ подставляются в bindings — только whitelist-валидация.
        foreach ([$index, $table] as $identifier) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
                throw new RuntimeException("Модуль [Migration]: недопустимый идентификатор [{$identifier}].");
            }
        }

        if ($this->db->driver() === 'mysql') {
            $found = $this->db->selectOne(
                'SELECT COUNT(*) AS c FROM information_schema.STATISTICS'
                . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
                [$table, $index]
            );
            if ((int) ($found['c'] ?? 0) > 0) {
                return 0;
            }
        } else {
            $exists = $this->db->selectOne(
                'SELECT name FROM sqlite_master WHERE type = ? AND tbl_name = ? AND name = ?',
                ['index', $table, $index]
            );
            if ($exists !== null) {
                return 0;
            }
        }

        return $this->sql("CREATE INDEX {$index} ON {$table} ({$columns})");
    }

    /** Идемпотентный хелпер: создать таблицу, только если её ещё нет. */
    final protected function createTableIfMissing(string $table, string $definition): int
    {
        if ($this->db->tableExists($table)) {
            return 0;
        }

        return $this->sql("CREATE TABLE {$table} ({$definition})");
    }

    /** Идемпотентный хелпер: удалить таблицу, если она есть. */
    final protected function dropTableIfExists(string $table): int
    {
        if (!$this->db->tableExists($table)) {
            return 0;
        }

        return $this->sql("DROP TABLE {$table}");
    }

    /** Идемпотентный хелпер: добавить колонку, если её ещё нет. */
    final protected function addColumnIfMissing(string $table, string $column, string $definition): int
    {
        foreach ($this->db->select("PRAGMA table_info({$table})") as $col) {
            if (($col['name'] ?? null) === $column) {
                return 0;
            }
        }

        return $this->sql("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }

    /** Откат по умолчанию — no-op (безопасно для add-only миграций). */
    public function down(): int
    {
        return 0;
    }
}
