<?php
declare(strict_types=1);

namespace App\Core\Module\Database\Contracts;

/**
 * Контракт модуля Database — доступ к СУБД (mysql|pgsql|sqlite через PDO).
 * Единственное, что видят другие модули (п.2 Конституции).
 */
interface DatabaseInterface
{
    /** Начать транзакцию. */
    public function beginTransaction(): void;

    /** Подтвердить транзакцию. */
    public function commit(): void;

    /** Откатить транзакцию. */
    public function rollBack(): void;

    /** Выполнить запрос с привязкой параметров (подготовленное выражение). @return int затронутых строк */
    public function run(string $sql, array $bindings = []): int;

    /** Запрос, возвращающий массив строк. */
    public function select(string $sql, array $bindings = []): array;

    /** Запрос, возвращающий первую строку или null. */
    public function selectOne(string $sql, array $bindings = []): ?array;

    /** Построитель запросов (возвращается как object — точный тип появится с реализацией). */
    public function query(string $table): object;

    /** Активный драйвер (mysql|pgsql|sqlite) — для Doctor/диагностики. */
    public function driver(): string;

    /** Проверка соединения (для Doctor). */
    public function ping(): bool;
}
