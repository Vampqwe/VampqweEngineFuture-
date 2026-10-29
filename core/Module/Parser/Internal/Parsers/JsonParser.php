<?php
declare(strict_types=1);

namespace App\Core\Module\Parser\Internal\Parsers;

/**
 * Внутренняя реализация JSON-парсера. ЗАПРЕЩЕНО использовать напрямую извне модуля —
 * только через ParserInterface (п.2 Конституции).
 */
final class JsonParser
{
    /** @return array<int|string,mixed> */
    public function decode(string $content): array
    {
        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<int|string,mixed> $data */
    public function encode(array $data, bool $pretty = false): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }
        return json_encode($data, $flags);
    }
}
