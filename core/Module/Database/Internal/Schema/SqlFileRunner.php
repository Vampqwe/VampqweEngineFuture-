<?php
declare(strict_types=1);

namespace App\Core\Module\Database\Internal\Schema;

use PDO;
use RuntimeException;

/**
 * Внутренний исполнитель SQL-дампов (модуль Database, Internal — п.2 Конституции).
 * ЗАПРЕЩЕНО использовать напрямую извне модуля.
 *
 * Назначение: прогонять дампы схемы вида database/db.sql (phpMyAdmin-dump)
 * через активное PDO-соединение по одному оператору.
 *
 * Почему парсер, а не PDO::exec() всего файла:
 *  - sqlite-драйвер PDO выполняет ТОЛЬКО первый оператор в multi-statement;
 *  - mysql-драйвер с нативными prepared statements (emulate_prepares=false,
 *    наша политика безопасности) тоже не принимает пачку DDL одним вызовом.
 *
 * Правила разбора (совместимы с дампами phpMyAdmin):
 *  - комментарии "-- ..." и "#" пропускаются (учитывая строки в кавычках);
 *  - условные MySQL-директивы (version comments) вырезаются целиком (переносимость);
 *  - оператор заканчивается на ";" вне кавычек/комментариев;
 *  - MySQL-специфичные операторы (SET SQL_MODE, START TRANSACTION, COMMIT, USE ...)
 *    пропускаются при работе НЕ на mysql — дамп из phpMyAdmin переносим на sqlite.
 */
final class SqlFileRunner
{
    /** @return list<string> выполненные операторы (для логов/отчётов) */
    public function run(PDO $pdo, string $sql, string $driver = 'mysql', array $existingTables = []): array
    {
        $executed = [];

        foreach ($this->statements($sql) as $statement) {
            // Идемпотентная досоздка: CREATE TABLE уже существующей таблицы
            // пропускаем (иначе повторный прогон дампа падает на DDL-конфликте).
            if ($existingTables !== [] && $this->createsExistingTable($statement, $existingTables)) {
                continue;
            }

            if ($driver === 'mysql') {
                try {
                    $pdo->exec($statement);
                } catch (\PDOException $e) {
                    throw new RuntimeException(
                        'Модуль [Database]: ошибка выполнения оператора из SQL-дампа: '
                        . $e->getMessage() . PHP_EOL
                        . 'Оператор: ' . mb_substr($statement, 0, 200),
                        (int) $e->getCode(),
                        $e
                    );
                }
                $executed[] = $statement;
                continue;
            }

            // --- не-mysql: портирование дампа phpMyAdmin (sqlite/pgsql) ---
            if ($this->isMysqlOnly($statement)) {
                continue; // SET/START TRANSACTION/COMMIT/USE — не для этой СУБД
            }

            if (preg_match('/^\s*ALTER\s+TABLE\b/i', $statement)) {
                // Дампы phpMyAdmin выносят ключи в постфиксные ALTER ... ADD KEY.
                // На sqlite их надо преобразовать в CREATE INDEX и прогнать ПОСЛЕ
                // создания таблиц; PRIMARY KEY/AUTO_INCREMENT игнорируем (в дампе
                // id без inline-PK -> INTEGER PRIMARY KEY AUTOINCREMENT по our-port).
                foreach ($this->splitAlterKeys($statement) as $indexStatement) {
                    $this->execute($pdo, $indexStatement, $executed);
                }
                continue;
            }

            $this->execute($pdo, $this->portToSqlite($statement), $executed);
        }

        return $executed;
    }

    /**
     * Идемпотентная досоздка: определяет, создаёт ли данный оператор таблицу,
     * которая уже присутствует в СУБД ($existingTables из information_schema
     * / sqlite_master). Используется при повторном прогоне дампа схемы (re-install),
     * чтобы не падать на DDL-конфликте "table already exists".
     *
     * Формат $existingTables — хеш-карта lowercase => true (как отдаёт
     * DatabaseManager::existingTables()): сверка по array_key_exists, а не
     * in_array по значениям (значения — bool, сравнение давало вечный false
     * и повторный прогон падал на первом же DDL).
     *
     * @param array<string,true> $existingTables
     */
    private function createsExistingTable(string $statement, array $existingTables): bool
    {
        if (!preg_match('/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"\[]?([A-Za-z0-9_]+)[`"\]]?/i', $statement, $m)) {
            return false; // CREATE TABLE ... AS SELECT / прочее — не наш случай
        }

        return array_key_exists(strtolower($m[1]), $existingTables);
    }

    private function execute(PDO $pdo, string $statement, array &$executed): void
    {
        try {
            $pdo->exec($statement);
        } catch (\PDOException $e) {
            throw new RuntimeException(
                'Модуль [Database]: ошибка выполнения оператора из SQL-дампа: '
                . $e->getMessage() . PHP_EOL
                . 'Оператор: ' . mb_substr($statement, 0, 200),
                (int) $e->getCode(),
                $e
            );
        }
        $executed[] = $statement;
    }

    /**
     * Разбирает постфиксный ALTER дампа на безопасные операторы переноса:
     *   ADD KEY / ADD UNIQUE KEY -> CREATE [UNIQUE] INDEX
     *   ADD PRIMARY KEY         -> пропускается (PK задаётся при портировании CREATE TABLE)
     *   MODIFY/CHANGE/DROP/ADD CONSTRAINT FK -> пропускаются (sqlite их не поддерживает
     *   инлайн; ON DELETE через ALTER требует per-table rebuild — вне скоупа ядра v1).
     *
     * @return list<string>
     */
    private function splitAlterKeys(string $statement): array
    {
        if (!preg_match('/^\s*ALTER\s+TABLE\s+`?([A-Za-z0-9_]+)`?\s*(.*)$/is', $statement, $m)) {
            return [];
        }
        $table  = $m[1];
        $clauses = preg_split('/,\s*\n\s*/', trim($m[2])) ?: [];
        $out    = [];

        foreach ($clauses as $clause) {
            $clause = trim($clause);
            if ($clause === '') {
                continue;
            }
            if (preg_match('/^ADD\s+(UNIQUE\s+)?KEY\s+`?([A-Za-z0-9_]+)`?\s*\(([^)]*)\)/i', $clause, $c)) {
                $unique = isset($c[1]) && $c[1] !== '' ? 'UNIQUE ' : '';
                $cols   = str_replace('`', '', $c[3]);
                // имя индекса уникально в масштабах БД sqlite -> префикс таблицы
                $name   = $table . '_' . $c[2];
                $out[]  = "CREATE {$unique}INDEX `{$name}` ON `{$table}` ({$cols})";
            }
            // ADD PRIMARY KEY / ADD CONSTRAINT / MODIFY / CHANGE — молча пропускаем
        }

        return $out;
    }

    /** MySQL-специфичные операторы, не имеющие смысла на других СУБД. */
    private function isMysqlOnly(string $statement): bool
    {
        return (bool) preg_match(
            '/^\s*(SET\s|START\s+TRANSACTION\b|BEGIN\s+WORK\b|COMMIT\b|ROLLBACK\b|USE\s|LOCK\s+TABLES\b|UNLOCK\s+TABLES\b|SET\s+FOREIGN_KEY_CHECKS\b)/i',
            $statement
        );
    }

    /**
     * Перенос MySQL-дампа на диалект SQLite (только для CREATE TABLE;
     * INSERT/SELECT оставляем как есть — стандартный SQL переносим).
     *
     * Убирается: `обратные кавычки`, CHARACTER SET/COLLATE, COMMENT '...',
     * ENGINE=..., AUTO_INCREMENT (-> INTEGER PRIMARY KEY AUTOINCREMENT),
     * INT UNSIGNED и размеры int(n), ON UPDATE CURRENT_TIMESTAMP.
     */
    private function portToSqlite(string $statement): string
    {
        if (!preg_match('/^\s*CREATE\s+TABLE/i', $statement)) {
            return $statement;
        }

        $out = str_replace('`', '', $statement);
        // enum('a','b') -> TEXT (CHECK-ограничения sqlite из дампов не переносим — мягко)
        $out = preg_replace('/\benum\s*\([^)]*\)/i', 'TEXT', $out);
        $out = preg_replace('/\bset\s*\([^)]*\)/i', 'TEXT', $out);
        // Типы: целочисленные MySQL -> INTEGER (с UNSIGNED и размерами).
        // ВАЖНО: \b перед UNSIGNED невозможен после ')' — поэтому сначала срезаем
        // размеры у int-типов, затем удаляем все UNSIGNED.
        $out = preg_replace('/\b(TINY|SMALL|MEDIUM|BIG)?INT\(\d+\)(\s+UNSIGNED)?/i', 'INTEGER', $out);
        $out = preg_replace('/\bBIGINT(\s+UNSIGNED)?/i', 'INTEGER', $out);
        $out = preg_replace('/\bINT(\s+UNSIGNED)?(?!\w)/i', 'INTEGER', $out);
        $out = preg_replace('/\s+UNSIGNED\b/i', '', $out);
        // JSON в sqlite нет — обычный TEXT (валидация на уровне приложения).
        $out = preg_replace('/\bjson\b/i', 'TEXT', $out);
        // CHARACTER SET / COLLATE — везде (в определениях колонок и таблицы).
        $out = preg_replace('/\s+CHARACTER\s+SET\s+[A-Za-z0-9_]+/i', '', $out);
        $out = preg_replace('/\s+COLLATE\s+[A-Za-z0-9_]+/i', '', $out);
        // COMMENT '...' — везде (колонки и таблица).
        $out = preg_replace("/\s+COMMENT\s+'(?:[^'\\\\]|\\\\.)*'/i", '', $out);
        // Хвост ") ENGINE=... DEFAULT CHARSET=...": оставляем только закрывающую скобку.
        $out = preg_replace('/\)\s+ENGINE=\w+.*$/is', ')', $out);
        $out = preg_replace('/\)\s+DEFAULT\s+CHARSET=\w+.*$/is', ')', $out);
        // timestamp с дефолтом CURRENT_TIMESTAMP в sqlite требует DATETIME.
        $out = preg_replace('/\btimestamp\b/i', 'DATETIME', $out);
        $out = preg_replace('/ON\s+UPDATE\s+CURRENT_TIMESTAMP(\(\d+\))?/i', '', $out);
        // Удалённые выше конструкции могли оставить «, ,» или хвостовую запятую перед ).
        $out = preg_replace('/,\s*,+/', ',', $out);
        $out = preg_replace('/,\s*\)/', "\n)", $out);

        // Дампы phpMyAdmin выносят PRIMARY KEY в постфиксный ALTER TABLE, а в
        // CREATE TABLE id остаётся «INTEGER NOT NULL». В SQLite rowid-таблица без
        // PK не даёт AUTOINCREMENT — INSERT без id падает. Делаем первую колонку
        // `id` полноценным INTEGER PRIMARY KEY AUTOINCREMENT (ровно одна на таблицу).
        if (preg_match('/^CREATE\s+TABLE\s+\S+\s*\(/i', $out) && str_contains($out, 'PRIMARY KEY') === false) {
            $out = preg_replace(
                '/(\()((?:[^"\']*"[^"\']*")?\s*id\s+)INTEGER\s+NOT\b/i',
                '$1$2INTEGER PRIMARY KEY AUTOINCREMENT NOT',
                $out,
                1,
                $count
            ) ?? $out;
            if ($count === 0) {
                // вариант без кавычек: "  id INTEGER NOT NULL,"
                $out = preg_replace(
                    '/(\(\s*(?:`\w+`|[\w]+)?\s*)\bid\s+INTEGER\s+NOT\s+NULL/i',
                    '$1id INTEGER PRIMARY KEY AUTOINCREMENT',
                    $out,
                    1,
                ) ?? $out;
            }
        }

        return $out;
    }

    /** Разбивает SQL на отдельные операторы, пропуская комментарии. @return list<string> */
    private function statements(string $sql): array
    {
        $out    = [];
        $buffer = '';
        $len    = strlen($sql);
        $quote  = null;   // текущая открывающая кавычка: ' " `
        $i      = 0;

        while ($i < $len) {
            $ch = $sql[$i];

            // --- комментарии (только вне кавычек) ---
            if ($quote === null) {
                if (($ch === '-' && ($sql[$i + 1] ?? '') === '-') || $ch === '#') {
                    $nl = strpos($sql, "\n", $i);
                    $i  = $nl === false ? $len : $nl + 1;
                    continue;
                }

                if ($ch === '/' && ($sql[$i + 1] ?? '') === '*') {
                    $end = strpos($sql, '*/', $i + 2);
                    if ($end === false) {
                        throw new RuntimeException('Модуль [Database]: незакрытый блок /* */ в SQL-дампе.');
                    }
                    // Обычные блочные комментарии и условные version-comments phpMyAdmin
                    // безопасно игнорируем ради переносимости дампа между драйверами.
                    $i = $end + 2;
                    continue;
                }
            }

            // --- состояния кавычки ---
            if ($quote !== null) {
                if ($ch === '\\' && $quote !== '`' && $i + 1 < $len) {
                    $buffer .= $ch . $sql[$i + 1];
                    $i      += 2;
                    continue;
                }
                if ($ch === $quote) {
                    if (($sql[$i + 1] ?? '') === $quote) {
                        // удвоенная кавычка ('' внутри ') — всё ещё строка
                        $buffer .= $ch . $ch;
                        $i      += 2;
                        continue;
                    }
                    $quote = null;
                }
                $buffer .= $ch;
                $i++;
                continue;
            }

            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote  = $ch;
                $buffer .= $ch;
                $i++;
                continue;
            }

            // --- конец оператора ---
            if ($ch === ';') {
                $stmt = trim($buffer);
                if ($stmt !== '') {
                    $out[] = $stmt;
                }
                $buffer = '';
                $i++;
                continue;
            }

            $buffer .= $ch;
            $i++;
        }

        $tail = trim($buffer);
        if ($tail !== '') {
            $out[] = $tail;
        }

        return $out;
    }
}
