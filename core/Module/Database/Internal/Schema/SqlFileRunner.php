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
 *  - оператор заканчивается на ";" вне кавычек/комментариев.
 */
final class SqlFileRunner
{
    /** @return list<string> выполненные операторы (для логов/отчётов) */
    public function run(PDO $pdo, string $sql): array
    {
        $executed = [];

        foreach ($this->statements($sql) as $statement) {
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
