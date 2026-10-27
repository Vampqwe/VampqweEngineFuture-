<?php
declare(strict_types=1);

namespace App\Core\Module\Template\Internal\Parsers;

/**
 * Внутренний нативный PHP-движок шаблонов (области видимости + вывод в буфер).
 * ЗАПРЕЩЕНО использовать напрямую извне модуля (п.2 Конституции).
 * Реализация — в плане.
 */
final class NativePhpEngine
{
    // TODO: render(string $__path, array $__data): string  через ob_start/extract-safe.
}
