<?php
declare(strict_types=1);

namespace App\Core\System\Support;

use App\Core\Module\FileSystem\Contracts\FileSystemInterface;
use App\Core\Module\Template\Contracts\TemplateInterface;
use App\Core\Module\Theme\Contracts\ThemeInterface;
use App\Core\System\Http\Request;
use App\Core\System\Http\Response;

/**
 * KernelDispatcher — рендер системных страниц ядра (главная) через контракты
 * модулей, доступные строго посредством ModuleBridge (правило R6b: ни одной
 * ссылки на классы модулей в executable-коде).
 *
 * Выделен из Kernel по SRP (п.5: расширение без правки ядра): конвейер
 * маршрутизации остаётся в Kernel, «что отдавать на /» — здесь. Логика
 * поведения сохранена 1:1: честность фундамента (503 при недоступной БД),
 * мультисайт-резолв темы, авто-клон стартера view/app/_starter, хук
 * theme.resolved, публикация тегов Assets в шаблоны Template, fallback-текст.
 */
final class KernelDispatcher
{
    public function __construct(private readonly ModuleBridge $bridge)
    {
    }

    /** GET / — главная страница движка (мультисайт + честность фундамента). */
    public function home(Request $request): Response
    {
        $appName = (string) $this->bridge->configValue('app.name', 'Unknown Engine');
        $debug   = (bool) $this->bridge->configValue('app.debug', false);

        $dbOk = true; $dbDriver = ''; $dbError = null;

        // Честность фундамента: если активирован драйвер Database, а соединения
        // нет — главная НЕ должна рапортовать «успешно запущен».
        $db = $this->bridge->database();
        if ($db !== null) {
            $dbError = $db->connectionError();
            if ($dbError !== null) {
                $dbOk = false;
                $dbDriver = $db->driver();
            }
        }
        $status = $dbOk ? 200 : 503;

        // Мультисайт: тема сайта по домену запроса — через модуль Theme
        // (реестр engine_sites -> эвристика поддомена -> дефолт из config/template.php).
        $theme = '';
        $site  = null;
        $themes = $this->bridge->themes();

        if ($themes !== null) {
            $host  = $request->header('host') ?? '';
            $theme = $themes->resolveForRequest($host);
            $site  = $themes->site();

            // Тема резолвлена, но её папки ещё нет (только что добавленный
            // сайт) — авто-клон стартера view/app/_starter. Движок не падает:
            // до клонирования рендер идёт через fallback '_default' в Template.
            $fs = $this->bridge->fileSystem();
            if ($site !== null && $site['theme'] !== '' && !$themes->exists($site['theme']) && $fs !== null) {
                $appView = $this->bridge->path('app_view_path');
                $starter = $appView . '/_starter';
                $target  = $appView . '/' . $site['theme'];
                if ($fs->isDirectory($starter) && $this->cloneStarter($fs, $starter, $target)) {
                    $themes->use($site['theme']);
                    $theme = $site['theme'];
                }
            }

            // Хук мультисайта: сторонние модули реагируют на резолв сайта
            // без правки ядра (п.4 Конституции).
            $hooks = $this->bridge->hooks();
            if ($theme !== '' && $hooks !== null) {
                $hooks->dispatch('theme.resolved', $theme, $site, $host);
            }
        }

        // Рендер через модуль Template (view/app/<theme>/home.phtml).
        $views = $this->bridge->templates();
        if ($views !== null) {
            // Статика темы — через реестр Assets (каскад site->theme->core):
            // регистрируем 'site.css'/'site.js' и публикуем теги в шаблоны.
            // Файлы темы меняются без правки layout; хук engine.assets.*
            // даёт модулям дописать своё (п.4). Если файлов нет — в каскаде
            // совпадения не будет, URL всё равно корректен (404 без ?v=),
            // а layout темы использует инлайн-fallback.
            $assets = $this->bridge->assets();
            if ($assets !== null) {
                $assets->useContext($site['domain'] ?? null, $theme !== '' ? $theme : ThemeInterface::DEFAULT_THEME);
                $assets->registerStyle('site', 'css/site.css', [], 'head');
                $assets->registerScript('site', 'js/site.js', [], 'footer');
                $views->share('assetsStyles', $assets->styles());
                $views->share('assetsScripts', $assets->scripts());
            }

            try {
                if ($theme !== '') {
                    $views->setTheme($theme);
                }
                $body = $views->render('home', [
                    'appName'    => $appName,
                    'debug'      => $debug,
                    'debugLabel' => $debug ? 'ON' : 'OFF',
                    'dbOk'       => $dbOk,
                    'dbDriver'   => $dbDriver,
                    'dbError'    => $dbError,
                    'site'       => $site,
                ], TemplateInterface::SCOPE_APP);
                return new Response($body, $status);
            } catch (\Throwable $e) {
                // сломанный шаблон не должен ронять движок — уходим в fallback-текст
                $this->bridge->log('error', 'Template: рендер home не удался, сработал fallback', ['exception' => $e, 'channel' => 'Template']);
            }
        }

        // Fallback без Template (например, шаблоны ещё не созданы).
        $body = $dbOk
            ? "✅ <b>" . htmlspecialchars($appName, ENT_QUOTES) . "</b> (Debug: " . ($debug ? 'ON' : 'OFF') . ") успешно запущен!"
            : "⚠️ <b>" . htmlspecialchars($appName, ENT_QUOTES) . "</b>: база данных «" . htmlspecialchars($dbDriver, ENT_QUOTES) . "» недоступна.";
        return new Response($body, $status);
    }

    /**
     * Мультисайт: рекурсивно клонирует папку-стартер темы (view/app/_starter)
     * в новую тему сайта. best effort: любой сбой FS означает «папки нет»,
     * рендер уйдёт через fallback '_default' — движок не падает (принцип п.9).
     */
    private function cloneStarter(
        FileSystemInterface $fs,
        string $source,
        string $target,
    ): bool {
        if (!$fs->createDirectory($target, 0775)) {
            return false;
        }

        foreach ((array) glob($source . '/*') as $entry) {
            $name = $fs->basename((string) $entry);
            if ($name === '' || $name[0] === '.') {
                continue;
            }
            $dest = $target . '/' . $name;
            if (is_dir($entry)) {
                if (!$this->cloneStarter($fs, (string) $entry, $dest)) {
                    return false;
                }
            } elseif (!$fs->copy((string) $entry, $dest)) {
                return false;
            }
        }

        return true;
    }
}
