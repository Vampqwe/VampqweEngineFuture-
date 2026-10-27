<?php
declare(strict_types=1);

namespace App\Core\Module\Queue\Internal\Drivers;

/**
 * Внутренний файловый драйвер очереди (storage/queue). ЗАПРЕЩЕНО извне модуля (п.2).
 * Реализация — в плане.
 */
final class FileQueueDriver
{
    public function __construct(private readonly string $directory)
    {
    }

    // TODO: сериализация JobInterface через Parser(json) + FileSystem.
}
