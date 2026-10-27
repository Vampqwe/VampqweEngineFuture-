<?php
declare(strict_types=1);

namespace App\Core\Module\Database\Internal\Connectors;

/**
 * Внутренний PDO-коннектор (сборка DSN из конфигурации database.*).
 * ЗАПРЕЩЕНО использовать напрямую извне модуля (п.2 Конституции).
 * Реализация — в плане.
 */
final class PdoConnector
{
    // TODO: make(array $config): \PDO  — mysql/pgsql/sqlite DSN + опции пула.
}
