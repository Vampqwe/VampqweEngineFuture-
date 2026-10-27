<?php
declare(strict_types=1);

namespace App\Core\System\Support;

/**
 * ЕДИНСТВЕННЫЙ разрешённый хардкод рендеринга (осознанное исключение из п.2 Конституции).
 *
 * Эта страница печатается, когда движок упал ДО инициализации ядра:
 *   — нет vendor/autoload.php (composer install не выполнен);
 *   — синтаксическая ошибка в config/modules.php или bootstrap-файлах;
 *   — фатальная ошибка при создании App/контейнера (до регистрации модулей Error/Exception).
 *
 * Поэтому здесь намеренно НЕТ зависимостей: ни контейнера, ни Logger, ни Template.
 * Только PHP + htmlspecialchars. После инициализации ядра этот класс не используется —
 * страницы ошибок рендерит модуль Error через контракт ErrorPageInterface.
 */
final class FatalPage
{
    /**
     * Рендер красивого 500 с трейсом (всегда показываем детали: раз уж упал сам
     * бутстрап — скрывать их бессмысленно, это dev-диагностика до конфигурации).
     */
    public static function render(\Throwable $e): string
    {
        $title    = '💥 VampqweEngineFuture: критическая ошибка при запуске';
        $message  = self::esc($e->getMessage());
        $file     = self::esc($e->getFile() . ':' . $e->getLine());
        $trace    = self::esc($e->getTraceAsString());
        $php      = PHP_VERSION;
        $state    = self::engineState();
        $hint     = self::hint($e);

        return <<<HTML
            <!doctype html>
            <html lang="ru">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>500 — Фатальная ошибка запуска</title>
            <style>
              :root { color-scheme: dark; }
              * { box-sizing: border-box; margin: 0; padding: 0; }
              body {
                font-family: -apple-system, "Segoe UI", Roboto, sans-serif;
                background: #12141a; color: #e6e9f0; min-height: 100vh;
                display: flex; align-items: center; justify-content: center; padding: 24px;
              }
              .card {
                max-width: 900px; width: 100%; background: #1b1e27;
                border: 1px solid #2c3040; border-radius: 14px; padding: 28px 32px;
                box-shadow: 0 12px 40px rgba(0,0,0,.45);
              }
              h1 { font-size: 20px; margin-bottom: 6px; color: #ff6b6b; }
              .sub { font-size: 13px; color: #9aa3b5; margin-bottom: 18px; }
              .row { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 18px; }
              .badge {
                font-size: 12px; padding: 4px 10px; border-radius: 999px;
                background: #232838; border: 1px solid #333a52; color: #c3cadb;
              }
              .err {
                background: #241a1e; border: 1px solid #5c2a31; border-radius: 10px;
                padding: 14px 16px; margin-bottom: 14px;
              }
              .err b { color: #ff8f8f; word-break: break-word; }
              .loc { font-family: ui-monospace, Consolas, monospace; font-size: 12px; color: #d0b8ff; margin-top: 6px; }
              pre {
                background: #10121a; border: 1px solid #262b3d; border-radius: 10px;
                padding: 14px 16px; overflow-x: auto; font-size: 12px; line-height: 1.55;
                color: #a9c7ff; white-space: pre-wrap; word-break: break-word;
              }
              details { margin-top: 14px; }
              summary { cursor: pointer; font-size: 13px; color: #9aa3b5; margin-bottom: 10px; }
              .hint {
                margin-top: 16px; font-size: 13px; color: #ffd28a;
                background: #24201a; border: 1px solid #5c4a2a; border-radius: 10px; padding: 12px 14px;
              }
              footer { margin-top: 18px; font-size: 11px; color: #5d6577; }
            </style>
            </head>
            <body>
            <div class="card">
              <h1>{$title}</h1>
              <div class="sub">Движок упал до инициализации ядра — сработал fallback-рендер (FatalPage).</div>
              <div class="row">
                <span class="badge">HTTP 500</span>
                <span class="badge">PHP {$php}</span>
                <span class="badge">Состояние: {$state}</span>
              </div>
              <div class="err">
                <b>{$message}</b>
                <div class="loc">{$file}</div>
              </div>
              <details open>
                <summary>Stack trace</summary>
                <pre>{$trace}</pre>
              </details>
              {$hint}
              <footer>Этот экран — единственный разрешённый хардкод рендеринга. После загрузки модулей
              ошибки отдаёт модуль Error (ErrorPageInterface).</footer>
            </div>
            </body>
            </html>
            HTML;
    }

    /** Отправить страницу 500 напрямую (используется index.php при падении бутстрапа). */
    public static function send(\Throwable $e): void
    {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo self::render($e);
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    /** Состояние движка по флагам — без контейнера, читаем Paths напрямую. */
    private static function engineState(): string
    {
        try {
            $paths = new Paths(BASE_PATH);
            $storage = $paths->get('storage');
            foreach (['installed' => 'установлен', 'installing' => 'установка', 'maintenance' => 'ТО', 'upgrading' => 'обновление'] as $flag => $label) {
                if (is_file($storage . '/.' . $flag)) {
                    return $label;
                }
            }
            return 'не установлен';
        } catch (\Throwable) {
            return 'неизвестно';
        }
    }

    /** Понятная подсказка по типу падения — частые причины раннего boot-failure. */
    private static function hint(\Throwable $e): string
    {
        $msg = $e->getMessage();

        if (str_contains($msg, 'vendor/autoload.php') || str_contains($msg, 'Failed opening required')) {
            $text = self::esc('Похоже, не выполнены зависимости. Запустите: composer install (или composer dump-autoload)');
        } elseif ($e instanceof \ParseError || str_contains($msg, 'syntax error')) {
            $text = self::esc('Синтаксическая ошибка в файле конфигурации/манифеста — проверьте указанный файл и строку выше.');
        } elseif ($e instanceof \RuntimeException && str_contains($msg, 'Манифест')) {
            $text = self::esc('Проблема с манифестом модулей config/modules.php — он должен возвращать массив описаний модулей.');
        } else {
            return '';
        }

        return '<div class="hint">💡 ' . $text . '</div>';
    }
}
