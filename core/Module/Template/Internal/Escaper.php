<?php
declare(strict_types=1);

namespace App\Core\Module\Template\Internal;

/**
 * Внутренний экранировщик вывода (запрещён к использованию извне модуля, п.2).
 *
 * Правило безопасности: {{ $var }} экранируется ВСЕГДА; {!! $var !!} — осознанный raw.
 */
final class Escaper
{
    public static function html(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '';
        }
        if (is_array($value) || is_object($value)) {
            $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return htmlspecialchars($json === false ? print_r($value, true) : $json, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
