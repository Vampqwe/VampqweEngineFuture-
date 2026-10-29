<?php
declare(strict_types=1);

namespace App\Core\Module\Database\Internal\QueryBuilders;

use App\Core\Module\Database\Contracts\DatabaseInterface;

/**
 * Внутренний Fluent-построитель SQL поверх DatabaseInterface::run()/select().
 * ЗАПРЕЩЕНО использовать напрямую извне модуля (п.2 Конституции).
 *
 * Безопасность: значения всегда уходят как bindings подготовленного выражения;
 * в SQL подставляются только идентификаторы, прошедшие строгую whitelist-валидацию.
 */
final class QueryBuilder
{
    /** @var list<string> */
    private array $columns = ['*'];
    /** @var list<array{sql:string, bindings:array<int|string,mixed>}> */
    private array $wheres = [];
    /** @var list<array{column:string, direction:string}> */
    private array $orders = [];
    private ?int $limit  = null;
    private ?int $offset = null;

    /** @param array<int|string,string> $columns */
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly string $table,
        array $columns = ['*'],
    ) {
        $this->columns = array_map(fn ($c): string => $this->wrapIdentifier((string) $c), $columns);
    }

    /** @param array<int|string,string> $columns */
    public function select(array $columns): self
    {
        $this->columns = array_map(fn ($c): string => $this->wrapIdentifier((string) $c), $columns);

        return $this;
    }

    /** where('status', '=', 'active') | where(['a'=>1,'b'=>2]) — значения только через bindings. */
    public function where(string|array $column, ?string $operator = null, mixed $value = null): self
    {
        if (is_array($column)) {
            foreach ($column as $k => $v) {
                $this->where((string) $k, '=', $v);
            }

            return $this;
        }

        $op = strtoupper(trim((string) $operator));
        if ($op === '') {
            $op = '=';
        }
        if (!in_array($op, ['=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE', 'IS', 'IS NOT'], true)) {
            throw new \InvalidArgumentException("Модуль [Database]: недопустимый оператор '{$op}'.");
        }

        if ($op === 'IS' || $op === 'IS NOT') {
            $this->wheres[] = ['sql' => $this->wrapIdentifier($column) . ' ' . $op . ' NULL', 'bindings' => []];
        } else {
            $this->wheres[] = [
                'sql'      => $this->wrapIdentifier($column) . ' ' . $op . ' ?',
                'bindings' => [$value],
            ];
        }

        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $dir = strtolower($direction) === 'desc' ? 'DESC' : 'ASC';
        $this->orders[] = ['column' => $this->wrapIdentifier($column), 'direction' => $dir];

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = max(0, $limit);

        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = max(0, $offset);

        return $this;
    }

    /** @return list<array<string,mixed>> */
    public function get(): array
    {
        [$sql, $bindings] = $this->compileSelect();

        return $this->db->select($sql, $bindings);
    }

    /** @return array<string,mixed>|null */
    public function first(): ?array
    {
        $rows = $this->limit(1)->get();

        return $rows[0] ?? null;
    }

    public function count(): int
    {
        $sql = 'SELECT COUNT(*) AS aggregate FROM ' . $this->wrapIdentifier($this->table)
             . $this->compileWheres();
        $row = $this->db->selectOne($sql, $this->collectBindings());

        return (int) ($row['aggregate'] ?? 0);
    }

    /** @param array<string,mixed> $values */
    public function insert(array $values): void
    {
        $cols = array_keys($values);
        $sql  = 'INSERT INTO ' . $this->wrapIdentifier($this->table)
              . ' (' . implode(', ', array_map([$this, 'wrapIdentifier'], $cols)) . ')'
              . ' VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';

        $this->db->run($sql, array_values($values));
    }

    /** @param array<string,mixed> $values */
    public function update(array $values): int
    {
        $sets     = [];
        $bindings = [];
        foreach ($values as $col => $val) {
            $sets[]     = $this->wrapIdentifier((string) $col) . ' = ?';
            $bindings[] = $val;
        }

        [$whereSql, $whereBindings] = $this->compileWheresWithBindings();
        $sql = 'UPDATE ' . $this->wrapIdentifier($this->table)
             . ' SET ' . implode(', ', $sets) . $whereSql;

        // run() возвращает число затронутых строк (PDOStatement::rowCount).
        return $this->db->run($sql, [...$bindings, ...$whereBindings]);
    }

    public function delete(): void
    {
        if ($this->wheres === []) {
            throw new \RuntimeException('Модуль [Database]: запрещён DELETE без WHERE (защита от потери данных).');
        }

        [$whereSql, $whereBindings] = $this->compileWheresWithBindings();
        $this->db->run('DELETE FROM ' . $this->wrapIdentifier($this->table) . $whereSql, $whereBindings);
    }

    // ==================== Internal compile ====================

    /** @return array{0:string, 1:list<mixed>} */
    private function compileSelect(): array
    {
        $sql = 'SELECT ' . implode(', ', $this->columns)
             . ' FROM ' . $this->wrapIdentifier($this->table)
             . $this->compileWheres();

        if ($this->orders !== []) {
            $sql .= ' ORDER BY ' . implode(', ', array_map(
                static fn (array $o): string => $o['column'] . ' ' . $o['direction'],
                $this->orders,
            ));
        }

        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit; // int — безопасно
        }
        if ($this->offset !== null) {
            $sql .= ' OFFSET ' . $this->offset;
        }

        return [$sql, $this->collectBindings()];
    }

    private function compileWheres(): string
    {
        if ($this->wheres === []) {
            return '';
        }

        return ' WHERE ' . implode(' AND ', array_column($this->wheres, 'sql'));
    }

    /** @return array{0:string, 1:list<mixed>} */
    private function compileWheresWithBindings(): array
    {
        return [$this->compileWheres(), $this->collectBindings()];
    }

    /** @return list<mixed> */
    private function collectBindings(): array
    {
        $bindings = [];
        foreach ($this->wheres as $w) {
            foreach ($w['bindings'] as $b) {
                $bindings[] = $b;
            }
        }

        return $bindings;
    }

    /** Строгая whitelist-валидация идентификаторов: буквы/цифры/underscore/точка (таблица.колонка). */
    private function wrapIdentifier(string $identifier): string
    {
        $id = trim($identifier);

        if ($id === '*') {
            return '*';
        }

        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $id)) {
            throw new \InvalidArgumentException(
                "Модуль [Database]: недопустимое имя идентификатора '{$id}' (whitelist: буквы, цифры, _)."
            );
        }

        return '`' . str_replace('.', '`.`', $id) . '`';
    }
}
