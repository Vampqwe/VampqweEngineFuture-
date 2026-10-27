<?php
declare(strict_types=1);

namespace App\Core\Module\Installer\Internal;

/**
 * Внутренний HTML рендера страниц установщика (запрещено извне модуля, п.2).
 * Совпадает по стилю с Support\FatalPage — единая визуальная тема движка.
 */
final class InstallerView
{
    /** @param array<string,mixed> $vars */
    public static function page(string $title, string $body, array $vars = []): string
    {
        $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $stepsBar = self::stepsBar((int) ($vars['step'] ?? 0));
        $php = PHP_VERSION;

        return <<<HTML
            <!doctype html>
            <html lang="ru">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{$t} — Установка VampqweEngineFuture</title>
            <style>
              :root { color-scheme: dark; }
              * { box-sizing: border-box; margin: 0; padding: 0; }
              body {
                font-family: -apple-system, "Segoe UI", Roboto, sans-serif;
                background: #12141a; color: #e6e9f0; min-height: 100vh;
                display: flex; align-items: center; justify-content: center; padding: 24px;
              }
              .card { max-width: 760px; width: 100%; background: #1b1e27;
                border: 1px solid #2c3040; border-radius: 14px; padding: 28px 32px;
                box-shadow: 0 12px 40px rgba(0,0,0,.45); }
              h1 { font-size: 20px; margin-bottom: 6px; }
              h2 { font-size: 15px; margin: 18px 0 8px; color: #c3cadb; }
              .sub { font-size: 13px; color: #9aa3b5; margin-bottom: 18px; }
              .steps { display: flex; gap: 6px; margin-bottom: 22px; }
              .st { flex: 1; text-align: center; font-size: 11px; padding: 6px 4px; border-radius: 8px;
                background: #232838; border: 1px solid #333a52; color: #7d8698; }
              .st.done { background: #1c2b22; border-color: #2e5c3f; color: #7fd6a1; }
              .st.active { background: #202a3f; border-color: #3f5c9e; color: #a9c7ff; }
              table { width: 100%; border-collapse: collapse; font-size: 13px; }
              td { padding: 7px 10px; border-bottom: 1px solid #262b3d; }
              .ok { color: #7fd6a1; } .fail { color: #ff8f8f; }
              label { display: block; font-size: 12px; color: #9aa3b5; margin: 12px 0 4px; }
              input, select {
                width: 100%; padding: 9px 11px; border-radius: 8px; font-size: 14px;
                background: #10121a; color: #e6e9f0; border: 1px solid #333a52;
              }
              input:focus, select:focus { outline: none; border-color: #4f6db4; }
              button {
                margin-top: 22px; padding: 11px 22px; border: 0; border-radius: 9px;
                background: #3f6ad8; color: #fff; font-size: 15px; cursor: pointer;
              }
              button:hover { background: #4f7ae8; }
              .errbox { background: #241a1e; border: 1px solid #5c2a31; border-radius: 10px;
                padding: 12px 14px; margin-bottom: 14px; color: #ff8f8f; font-size: 13px; }
              .success { background: #1c2b22; border: 1px solid #2e5c3f; border-radius: 10px;
                padding: 12px 14px; margin-bottom: 14px; color: #7fd6a1; font-size: 13px; }
              code { font-family: ui-monospace, Consolas, monospace; font-size: 12px; color: #d0b8ff; }
              footer { margin-top: 18px; font-size: 11px; color: #5d6577; }
            </style>
            </head>
            <body>
            <div class="card">
              {$stepsBar}
              <h1>{$t}</h1>
              {$body}
              <footer>VampqweEngineFuture · Installer · PHP {$php}</footer>
            </div>
            </body>
            </html>
            HTML;
    }

    private static function stepsBar(int $active): string
    {
        $labels = ['1. Требования', '2. Настройки', '3. Готово'];
        $html = '<div class="steps">';
        foreach ($labels as $i => $label) {
            $n = $i + 1;
            $cls = $n < $active ? 'st done' : ($n === $active ? 'st active' : 'st');
            $html .= '<div class="' . $cls . '">' . $label . '</div>';
        }
        return $html . '</div>';
    }

    public static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}
