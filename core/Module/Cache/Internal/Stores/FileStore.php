<?php
declare(strict_types=1);

namespace App\Core\Module\Cache\Internal\Stores;

/**
 * Внутренний файловый стор кэша (storage/cache). ЗАПРЕЩЕНО извне модуля (п.2).
 * Реализация — в плане; сейчас это описание контракта внутреннего хранилища.
 */
final class FileStore
{
    public function __construct(private readonly string $directory)
    {
    }

    // TODO: реализация read/write/forget/flush через FileSystemInterface-пути.
}
