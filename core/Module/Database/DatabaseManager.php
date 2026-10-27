<?php
declare(strict_types=1);

namespace App\Core\Module\Database;

use App\Core\Module\Database\Contracts\DatabaseInterface;
use RuntimeException;

/**
 * Фасад модуля Database (менеджер соединений).
 *
 * Зависимости: Config (database.driver и credentials), Logger (ошибки соединений).
 * РЕАЛИЗАЦИЯ В ПЛАНЕ — заглушки дают понятное сообщение вместо фатала.
 */
final class DatabaseManager implements DatabaseInterface
{
    public function __construct(private readonly string $driver = 'sqlite')
    {
    }

    public function beginTransaction(): void
    {
        throw new RuntimeException('Модуль [Database] объявлен в ядре, но ещё не реализован.');
    }

    public function commit(): void
    {
        throw new RuntimeException('Модуль [Database] ещё не реализован.');
    }

    public function rollBack(): void
    {
        throw new RuntimeException('Модуль [Database] ещё не реализован.');
    }

    public function run(string $sql, array $bindings = []): void
    {
        throw new RuntimeException('Модуль [Database] ещё не реализован.');
    }

    public function select(string $sql, array $bindings = []): array
    {
        throw new RuntimeException('Модуль [Database] ещё не реализован.');
    }

    public function selectOne(string $sql, array $bindings = []): ?array
    {
        throw new RuntimeException('Модуль [Database] ещё не реализован.');
    }

    public function query(string $table): object
    {
        throw new RuntimeException('Модуль [Database] ещё не реализован.');
    }

    public function ping(): bool
    {
        return false; // честно сообщаем Doctor: соединения нет
    }
}
