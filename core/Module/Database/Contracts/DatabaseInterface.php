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

    /** Прогон SQL-дампа (DDL схемы) через активное соединение. @return int выполненных операторов */
    public function migrate(string $sql): int;

    /** Существует ли таблица в активной БД (идемпотентность миграций/схемы). */
    public function tableExists(string $table): bool;

    /** Активный драйвер (mysql|pgsql|sqlite) — для Doctor/диагностики. */
    public function driver(): string;

    /** Проверка соединения (для Doctor). */
    public function ping(): bool;

    /**
     * Причина недоступности соединения (текст PDO/конфига) или null, если всё ок.
     * ping() глотает исключение ради безопасности диагностики — этот метод
     * возвращает его текст для health-эндпоинта и Doctor (без утечек наружу:
     * показывается только в debug-режиме / админских каналах).
     */
    public function connectionError(): ?string;
}
