<?php
declare(strict_types=1);

namespace App\Core\System\Support;

use App\Core\System\Enums\AppState;

/**
 * ЕДИНСТВЕННЫЙ разрешённый хардкод рендеринга (осознанное исключение из п.2 Конституции).
 *
 * @constitution-exception п.2 — рендер фатальной страницы ДО инициализации
 * ядра намеренно не имеет зависимостей (контейнера нет); BASE_PATH здесь —
 * единственный bootstrap-потребитель путей вне контейнера (записан в белый
 * список R8 линтера, аннотация обязательна).
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
              /* Единая системная тема движка (GitHub dark). Токены ПОВТОРЯЮТ
                 view/core/assets/css/engine.css — менять вместе (см. шапку того
                 файла). Inline намеренно: FatalPage рендерится до инициализации
                 ядра, когда модуль Assets и /assets/* могут быть недоступны. */
              :root {
                color-scheme: dark;
                --bg: #0d1117; --panel: #161b22; --elevated: #1c2128; --inset: #010409;
                --border: #30363d; --text: #e6edf3; --muted: #8b949e;
                --link: #4493f8; --red: #f85149; --purple: #bc8cff; --amber: #d29922;
              }
              * { box-sizing: border-box; margin: 0; padding: 0; }
              body {
                font-family: -apple-system, "Segoe UI", Roboto, sans-serif;
                background: var(--bg); color: var(--text); min-height: 100vh;
                display: flex; align-items: center; justify-content: center; padding: 24px;
              }
              .card {
                max-width: 900px; width: 100%; background: var(--panel);
                border: 1px solid var(--border); border-radius: 14px; padding: 28px 32px;
                box-shadow: 0 12px 40px rgba(1, 4, 9, 0.5);
              }
              h1 { font-size: 20px; margin-bottom: 6px; color: var(--red); }
              .sub { font-size: 13px; color: var(--muted); margin-bottom: 18px; }
              .row { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 18px; }
              .badge {
                font-size: 12px; padding: 4px 10px; border-radius: 999px;
                background: var(--elevated); border: 1px solid var(--border); color: var(--text);
              }
              .err {
                background: rgba(248, 81, 73, 0.08); border: 1px solid rgba(248, 81, 73, 0.4);
                border-left: 3px solid var(--red); border-radius: 10px;
                padding: 14px 16px; margin-bottom: 14px;
              }
              .err b { color: #ffa198; word-break: break-word; }
              .loc { font-family: ui-monospace, SFMono-Regular, Consolas, monospace; font-size: 12px; color: var(--purple); margin-top: 6px; }
              pre {
                background: var(--inset); border: 1px solid var(--border); border-radius: 10px;
                padding: 14px 16px; overflow-x: auto; font-size: 12px; line-height: 1.55;
                color: #a9c7ff; white-space: pre-wrap; word-break: break-word;
              }
              details { margin-top: 14px; }
              summary { cursor: pointer; font-size: 13px; color: var(--muted); margin-bottom: 10px; }
              .hint {
                margin-top: 16px; font-size: 13px; color: #e3b341;
                background: rgba(210, 153, 34, 0.08); border: 1px solid rgba(210, 153, 34, 0.4);
                border-left: 3px solid var(--amber); border-radius: 10px; padding: 12px 14px;
              }
              a { color: var(--link); text-decoration: none; }
              a:hover { text-decoration: underline; }
              footer { margin-top: 18px; font-size: 11px; color: #6e7681; }
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

    /**
     * Состояние движка по флагам — без контейнера (упал до boot): единый
     * резолвер AppState::detect() (п.10 — одна реализация детекта, Kernel
     * вызывает ту же). Имена маркеров живут только в AppState::flagNames().
     */
    private static function engineState(): string
    {
        try {
            $state = AppState::detect((new Paths(BASE_PATH))->get('storage'));

            return match ($state) {
                AppState::Running      => 'установлен',
                AppState::Installing   => 'установка',
                AppState::Upgrading    => 'обновление',
                AppState::Maintenance  => 'ТО',
                AppState::Error        => 'ошибка',
                AppState::NotInstalled => 'не установлен',
            };
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
