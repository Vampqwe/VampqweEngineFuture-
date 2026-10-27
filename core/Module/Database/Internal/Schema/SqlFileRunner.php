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
    public function run(PDO $pdo, string $sql, string $driver = 'mysql'): array
    {
        $executed = [];

        foreach ($this->statements($sql) as $statement) {
            if ($driver !== 'mysql') {
                if ($this->isMysqlOnly($statement)) {
                    continue; // SET/START TRANSACTION/COMMIT/USE — не для этой СУБД
                }
                if (preg_match('/^\s*ALTER\s+TABLE/i', $statement)) {
                    continue; // ALTER-«хвосты» дампа (MODIFY AUTO_INCREMENT) — CREATE уже перенесён
                }
                $statement = $this->portToSqlite($statement);
            }

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

        return $executed;
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
        $out = preg_replace('/\s+(ENGINE|COLLATE|AUTO_INCREMENT)\s*=?\s*[A-Za-z0-9_]*/i', '', $out);
        $out = preg_replace('/\s+CHARACTER\s+SET\s+[A-Za-z0-9_]+/i', '', $out);
        $out = preg_replace('/\s+COMMENT\s+\'(?:[^\']|\'\')*\'/i', '', $out);
        $out = preg_replace('/\bINT(?:\(\d+\))?\s+UNSIGNED\s+NOT\s+NULL\s+AUTO_INCREMENT/i',
            'INTEGER PRIMARY KEY AUTOINCREMENT', $out);
        // Варианты порядка слов в дампах: NOT NULL ... AUTO_INCREMENT / просто AUTO_INCREMENT
        $out = preg_replace('/\bBIGINT(?:\(\d+\))?(?:\s+UNSIGNED)?[^,]*AUTO_INCREMENT\b/i',
            'INTEGER PRIMARY KEY AUTOINCREMENT', $out);
        $out = preg_replace('/\bINT(?:\(\d+\))?(?:\s+UNSIGNED)?\s+[^,]*AUTO_INCREMENT\b/i',
            'INTEGER PRIMARY KEY AUTOINCREMENT', $out);
        $out = preg_replace('/\bBIGINT(\(\d+\))?\s+UNSIGNED\b/i', 'INTEGER', $out);
        $out = preg_replace('/\bTINYINT(\(\d+\))?(\s+UNSIGNED)?/i', 'INTEGER', $out);
        $out = preg_replace('/\bSMALLINT(\(\d+\))?(\s+UNSIGNED)?/i', 'INTEGER', $out);
        $out = preg_replace('/\b(MEDIUM)?INT\(\d+\)/i', 'INTEGER', $out);
        $out = preg_replace('/\s+UNSIGNED(?=\s|,|$)/i', '', $out);
        $out = preg_replace('/ON\s+UPDATE\s+CURRENT_TIMESTAMP(\(\d+\))?/i', '', $out);
        // Каскадные удалённые CONSTRAINT KEY ... (уникальные индексы создаются дампом отдельно)
        $out = preg_replace('/,\s*\n\s*(?=[,)])/', "\n", $out);

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
