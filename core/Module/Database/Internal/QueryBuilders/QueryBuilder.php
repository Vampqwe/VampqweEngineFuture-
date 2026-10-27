<?php
declare(strict_types=1);

namespace App\Core\Module\Database\Internal\QueryBuilders;

/**
 * Внутренний Fluent-построитель SQL (select/where/orderBy/limit/get).
 * ЗАПРЕЩЕНО использовать напрямую извне модуля (п.2 Конституции).
 * Реализация — в плане.
 */
final class QueryBuilder
{
    // TODO: fluent-API поверх DatabaseInterface::run().
}
