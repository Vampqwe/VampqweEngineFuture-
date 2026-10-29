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
              /* Fallback-стили установщика (Template/Assets недоступны).
                 Токены ПОВТОРЯЮТ view/core/assets/css/engine.css (GitHub dark) —
                 менять вместе. */
              :root {
                color-scheme: dark;
                --bg: #0d1117; --panel: #161b22; --elevated: #1c2128; --inset: #010409;
                --border: #30363d; --text: #e6edf3; --muted: #8b949e;
                --accent-hover: #388bfd; --green: #3fb950; --red: #f85149; --purple: #bc8cff;
              }
              * { box-sizing: border-box; margin: 0; padding: 0; }
              body {
                font-family: -apple-system, "Segoe UI", Roboto, sans-serif;
                background: var(--bg); color: var(--text); min-height: 100vh;
                display: flex; align-items: center; justify-content: center; padding: 24px;
              }
              .card { max-width: 760px; width: 100%; background: var(--panel);
                border: 1px solid var(--border); border-radius: 14px; padding: 28px 32px;
                box-shadow: 0 12px 40px rgba(1, 4, 9, 0.5); }
              h1 { font-size: 20px; margin-bottom: 6px; }
              h2 { font-size: 15px; margin: 18px 0 8px; color: var(--text); }
              .sub { font-size: 13px; color: var(--muted); margin-bottom: 18px; }
              .steps { display: flex; gap: 6px; margin-bottom: 22px; }
              .st { flex: 1; text-align: center; font-size: 11px; padding: 6px 4px; border-radius: 8px;
                background: var(--elevated); border: 1px solid var(--border); color: #6e7681; }
              .st.done { background: rgba(63, 185, 80, 0.1); border-color: rgba(63, 185, 80, 0.4); color: var(--green); }
              .st.active { background: rgba(31, 111, 235, 0.15); border-color: #1f6feb; color: #79c0ff; }
              table { width: 100%; border-collapse: collapse; font-size: 13px; }
              td { padding: 7px 10px; border-bottom: 1px solid #21262d; }
              .ok { color: var(--green); } .fail { color: var(--red); }
              label { display: block; font-size: 12px; color: var(--muted); margin: 12px 0 4px; }
              input, select {
                width: 100%; padding: 9px 11px; border-radius: 8px; font-size: 14px;
                background: var(--inset); color: var(--text); border: 1px solid var(--border);
              }
              input:focus, select:focus { outline: none; border-color: var(--accent-hover);
                box-shadow: 0 0 0 3px rgba(56, 139, 253, 0.3); }
              button {
                margin-top: 22px; padding: 10px 22px; border: 1px solid rgba(240, 246, 252, 0.1);
                border-radius: 8px;
                background: linear-gradient(180deg, #2ea043, #238636);
                color: #fff; font-size: 14px; font-weight: 600; cursor: pointer;
              }
              button:hover { background: #2ea043; }
              .errbox { background: rgba(248, 81, 73, 0.08); border: 1px solid rgba(248, 81, 73, 0.4);
                border-left: 3px solid var(--red); border-radius: 10px;
                padding: 12px 14px; margin-bottom: 14px; color: #ffa198; font-size: 13px; }
              .success { background: rgba(63, 185, 80, 0.08); border: 1px solid rgba(63, 185, 80, 0.4);
                border-left: 3px solid var(--green); border-radius: 10px;
                padding: 12px 14px; margin-bottom: 14px; color: #7ee787; font-size: 13px; }
              code { font-family: ui-monospace, SFMono-Regular, Consolas, monospace; font-size: 12px; color: var(--purple); }
              a { color: #4493f8; text-decoration: none; }
              a:hover { text-decoration: underline; }
              footer { margin-top: 18px; font-size: 11px; color: #6e7681; }
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
