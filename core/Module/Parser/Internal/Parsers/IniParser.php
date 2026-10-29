<?php
declare(strict_types=1);

namespace App\Core\Module\Parser\Internal\Parsers;

/**
 * Внутренний INI-парсер. Только для служебных файлов движка.
 * ЗАПРЕЩЕНО использовать напрямую извне модуля (п.2 Конституции).
 */
final class IniParser
{
    /** @return array<string,mixed> */
    public function decode(string $content): array
    {
        $parsed = parse_ini_string($content, true, INI_SCANNER_TYPED);
        return $parsed === false ? [] : $parsed;
    }

    /** @param array<string,mixed> $data */
    public function encode(array $data, bool $pretty = false): string
    {
        $lines = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $lines[] = "[{$key}]";
                foreach ($value as $k => $v) {
                    $lines[] = "{$k}=" . $this->scalar((string) $v);
                }
            } else {
                $lines[] = "{$key}=" . $this->scalar((string) $value);
            }
        }
        return implode("\n", $lines);
    }

    private function scalar(string $value): string
    {
        return str_contains($value, ' ') ? '"' . $value . '"' : $value;
    }
}
