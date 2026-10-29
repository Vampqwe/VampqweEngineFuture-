<?php
declare(strict_types=1);

namespace App\Core\Module\Migration\Internal;

use App\Core\Module\Database\Contracts\DatabaseInterface;
use App\Core\Module\FileSystem\Contracts\FileSystemInterface;
use App\Core\Module\Hook\Contracts\HookInterface;
use App\Core\Module\Logger\Contracts\LoggerInterface;
use App\Core\Module\Migration\Contracts\MigrationInterface;
use App\Core\Module\TimeDate\Contracts\TimeDateInterface;
use RuntimeException;

/**
 * Реализация модуля Migration — исполнитель SQL-дампов схемы (внутренняя
 * кухня модуля, п.2 Конституции: наружу только MigrationInterface).
 *
 * Единственный источник схемы — database/*.sql (каталог из Paths::MAP
 * ['database']). Файлы применяются по алфавиту; каждый прогон идемпотентен
 * (Database::migrate(skipExisting: true) пропускает уже существующие
 * таблицы и досоздаёт недостающие — частичная схема не валит установку).
 *
 * Зависимости — только контракты (п.3): Database, FileSystem, TimeDate,
 * Logger, Hook. Хук-события: migration.before / migration.after.
 * Реестр применённых дампов — таблица engine_migrations (создаётся
 * автоматически при первом обращении; нужна для будущей admin-опции
 * «Миграция БД» и честного статуса в Doctor).
 */
final class SqlSchemaMigrator implements MigrationInterface
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly FileSystemInterface $fs,
        private readonly TimeDateInterface $time,
        private readonly LoggerInterface $logger,
        private readonly HookInterface $hook,
        /** Каталог SQL-дампов — Paths::get('database') (единая точка правды, п.7). */
        private readonly string $databasePath,
    ) {
    }

    // ==================== MigrationInterface ====================

    public function files(): array
    {
        if ($this->databasePath === '' || !$this->fs->isDirectory($this->databasePath)) {
            return [];
        }

        // glob не гарантирует порядок — сортируем по имени явно (алфавит).
        $found = glob($this->databasePath . '/*.sql') ?: [];
        sort($found, SORT_STRING);

        return array_values(array_filter($found, static fn (string $f): bool => is_file($f)));
    }

    public function hasSchemaSource(): bool
    {
        foreach ($this->files() as $file) {
            if ($this->tablesIn((string) $this->fs->read($file)) !== []) {
                return true;
            }
        }

        return false;
    }

    public function plan(): array
    {
        $tables  = [];
        $files   = $this->files();

        foreach ($files as $file) {
            $tables = array_merge($tables, $this->tablesIn((string) $this->fs->read($file)));
        }

        $tables  = array_values(array_unique($tables));
        $missing = array_values(array_filter(
            $tables,
            fn (string $t): bool => !$this->safeTableExists($t),
        ));

        return ['files' => $files, 'tables' => $tables, 'missing' => $missing];
    }

    public function run(): array
    {
        $files = $this->files();

        if ($files === []) {
            throw new RuntimeException(
                "Модуль [Migration]: в каталоге {$this->databasePath} нет SQL-дампов (*.sql) — источник схемы отсутствует."
            );
        }

        $statements = 0;
        $applied    = [];

        foreach ($files as $file) {
            $name = $this->fs->basename($file);
            $sql  = (string) $this->fs->read($file);

            // Дамп без CREATE TABLE — не источник схемы: предупреждение пользователю
            // (через исключение, которое Installer конвертирует в шаг «создайте БД»).
            if ($this->tablesIn($sql) === []) {
                throw new RuntimeException(
                    "Модуль [Migration]: дамп [{$name}] не содержит CREATE TABLE — базу данных необходимо создать вручную."
                );
            }

            $this->hook->dispatch('migration.before', $name);

            $count          = $this->db->migrate($sql, skipExisting: true);
            $statements    += $count;
            $applied[]      = $name;

            $this->ensureRegistry();
            $this->recordApplied($name, $count);

            $this->logger->info("Схема применена из дампа [{$name}]: {$count} операторов", ['channel' => 'Migration']);
            $this->hook->dispatch('migration.after', $name, $count);
        }

        return ['statements' => $statements, 'files' => $applied, 'skipped' => false];
    }

    // ==================== внутренняя кухня ====================

    /**
     * Имена таблиц из SQL-дампа (CREATE TABLE [IF NOT EXISTS] `name`).
     *
     * @return list<string>
     */
    private function tablesIn(string $sql): array
    {
        preg_match_all(
            '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"\[]?([A-Za-z0-9_]+)[`"\]]?/i',
            $sql,
            $m,
        );

        return array_values(array_unique($m[1] ?? []));
    }

    /** tableExists с защитой: недоступная СУБД => «таблицы нет» (честный план). */
    private function safeTableExists(string $table): bool
    {
        try {
            return $this->db->tableExists($table);
        } catch (\Throwable) {
            return false;
        }
    }

    /** Идемпотентный реестр применённых дампов (для Doctor/админ-опции в будущем). */
    private function ensureRegistry(): void
    {
        static $checked = false;

        if ($checked) {
            return;
        }
        $checked = true;

        if ($this->safeTableExists(MigrationInterface::REGISTRY_TABLE)) {
            return;
        }

        $definition = $this->db->driver() === 'mysql'
            ? 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
              . "dump VARCHAR(191) NOT NULL UNIQUE,"
              . "statements INT NOT NULL DEFAULT 0,"
              . "applied_at VARCHAR(40) NOT NULL DEFAULT ''"
            : 'id INTEGER PRIMARY KEY AUTOINCREMENT,'
              . "dump VARCHAR(191) NOT NULL UNIQUE,"
              . "statements INTEGER NOT NULL DEFAULT 0,"
              . "applied_at VARCHAR(40) NOT NULL DEFAULT ''";

        $this->db->run('CREATE TABLE IF NOT EXISTS ' . MigrationInterface::REGISTRY_TABLE . ' (' . $definition . ')');
    }

    /** Запись факта применения дампа (no-op при повторе — UNIQUE dump). */
    private function recordApplied(string $dump, int $statements): void
    {
        try {
            $exists = $this->db->selectOne(
                'SELECT id FROM ' . MigrationInterface::REGISTRY_TABLE . ' WHERE dump = ?',
                [$dump],
            );

            if ($exists === null) {
                $this->db->run(
                    'INSERT INTO ' . MigrationInterface::REGISTRY_TABLE . ' (dump, statements, applied_at) VALUES (?, ?, ?)',
                    [$dump, $statements, $this->time->toIso($this->time->now())],
                );
            } else {
                $this->db->run(
                    'UPDATE ' . MigrationInterface::REGISTRY_TABLE . ' SET statements = ?, applied_at = ? WHERE dump = ?',
                    [$statements, $this->time->toIso($this->time->now()), $dump],
                );
            }
        } catch (\Throwable $e) {
            // Реестр — диагностика, не источник истины схемы: ошибка не валит установку (п.9).
            $this->logger->warning('Реестр миграций не обновлён: ' . $e->getMessage(), ['channel' => 'Migration']);
        }
    }
}
