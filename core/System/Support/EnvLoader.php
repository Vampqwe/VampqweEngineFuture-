<?php
declare(strict_types=1);

namespace App\Core\System\Support;

/**
 * Минимальный загрузчик .env-файлов.
 *
 * Значения попадают в getenv()/$_ENV/$_SERVER, но НЕ перезаписывают
 * уже установленные переменные окружения (важно для Docker/CI).
 */
class EnvLoader
{
    public static function load(string $filePath): void
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return;
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }

            $name  = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));

            // Срезаем кавычки: KEY="value" / KEY='value'
            if (strlen($value) > 1
                && (($value[0] === '"' && str_ends_with($value, '"'))
                 || ($value[0] === "'" && str_ends_with($value, "'")))
            ) {
                $value = substr($value, 1, -1);
            }

            if ($name === '' || getenv($name) !== false) {
                continue;
            }

            putenv("{$name}={$value}");
            $_ENV[$name]    = $value;
            $_SERVER[$name] = $value;
        }
    }
}
