<?php
declare(strict_types=1);

namespace App\Core\System\Support;

/**
 * Минимальный загрузчик .env-файлов.
 *
 * .env — единственная точка правды конфигурации окружения приложения:
 * значения из файла ПРИНУДИТЕЛЬНО замещают $_ENV/$_SERVER/getenv(),
 * иначе системные переменные хоста (например, случайное DB_DRIVER=...
 * в shell) ломают установку и выбор драйвера БД.
 * Для внешнего переопределения (Docker/CI) используйте ENV_OVERRIDE=1.
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

            // Инлайн-комментарий: KEY=value # пояснение или KEY= # пояснение.
            // Работает, только если перед # стоит пробел ИЛИ значение пустое;
            // внутри кавычек символ # не трогается (ветка срезки кавычек ниже).
            if ($value !== '' && $value[0] !== '"' && $value[0] !== "'") {
                $hashPos = strpos($value, ' #');
                if ($hashPos === false && str_starts_with($value, '#')) {
                    $hashPos = 0;
                }
                if ($hashPos !== false) {
                    $value = rtrim(substr($value, 0, $hashPos));
                }
            }

            // Срезаем кавычки: KEY="value" / KEY='value'
            if (strlen($value) > 1
                && (($value[0] === '"' && str_ends_with($value, '"'))
                 || ($value[0] === "'" && str_ends_with($value, "'")))
            ) {
                $value = substr($value, 1, -1);
            }

            if ($name === '') {
                continue;
            }

            // По умолчанию .env выигрывает у переменных окружения хоста.
            // ENV_OVERRIDE=1 (в самом .env или в shell) включает старое поведение
            // «не перезаписывать уже установленные» — для Docker/CI.
            $override = filter_var(
                getenv('ENV_OVERRIDE') ?: ($_ENV['ENV_OVERRIDE'] ?? ''),
                FILTER_VALIDATE_BOOLEAN
            );
            if ($override && getenv($name) !== false && $name !== 'ENV_OVERRIDE') {
                continue;
            }

            putenv("{$name}={$value}");
            $_ENV[$name]    = $value;
            $_SERVER[$name] = $value;
        }
    }
}
