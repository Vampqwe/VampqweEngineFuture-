<?php
declare(strict_types=1);

namespace App\Core\Module\Template\Internal;

/**
 * Экранирование вывода шаблонов (внутренняя кухня Template).
 *
 * {{ $var }}        -> htmlspecialchars (XSS-safe)
 * {!! $var !!}      -> raw (осознанный вывод HTML)
 */
final class Escaper
{
    public function escape(mixed $value): string
    {
        if ($value === null || $value === false) {
            return '';
        }

        if ($value instanceof \Stringable) {
            $value = (string) $value;
        } elseif (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        } else {
            $value = (string) $value;
        }

        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
