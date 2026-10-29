<?php
declare(strict_types=1);

namespace App\Core\System\Support;

use App\Core\System\Http\Request;
use App\Core\System\Http\Response;
use App\Core\System\Support\SiteIdentity;

/**
 * KernelDispatcher — рендер системных страниц ядра (главная) через контракты
 * модулей, доступные строго посредством ModuleBridge (правило R6b: ни одной
 * ссылки на классы модулей в executable-коде).
 *
 * Выделен из Kernel по SRP (п.5: расширение без правки ядра): конвейер
 * маршрутизации остаётся в Kernel, «что отдавать на /» — здесь. Логика
 * поведения сохранена 1:1: честность фундамента (503 при недоступной БД),
 * мультисайт-резолв темы, авто-клон стартера view/app/engineStart-starter, хук
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

        // Мультисайт: тема сайта по домену — через модуль Theme.
        // Домен берётся из идентичности экземпляра (SITE_DOMAIN / BASE_URL_SITE,
        // «один запуск = один сайт»); пустая идентичность -> легаси: Host
        // запроса + реестр engine_sites -> эвристика поддомена -> дефолт.
        $theme = '';
        $site  = null;
        $themes = $this->bridge->themes();

        if ($themes !== null) {
            $configured = $this->bridge->siteDomain();
            $host = $configured !== ''
                ? $configured
                : SiteIdentity::normalizeHost((string) ($request->header('host') ?? ''));

            // Само-регистрация идентичности (концепция «один запуск = один
            // сайт»): если экземпляр объявил домен в BASE_URL_SITE/SITE_DOMAIN,
            // но его ещё нет в реестре engine_sites — добавляем запись с темой
            // по умолчанию. Идемпотентно и best effort: при недоступной БД
            // ensureSite() вернёт false и резолв деградирует как раньше (п.9).
            // Без этого шага конфигурационный домен не имел бы записи в реестре,
            // resolve() отдавал бы первую запись ('localhost'), а авто-клон
            // темы никогда бы не запускался — новый сайт выглядел бы сломанным.
            if ($configured !== '') {
                $themes->ensureSite($configured, $this->bridge->defaultTheme(), $appName);
            }

            $theme = $themes->resolveForRequest($host);
            $site  = $themes->site();

            // Тема резолвлена, но её папки ещё нет (только что добавленный
            // сайт) — авто-клон стартера view/app/engineStart-starter. Движок не падает:
            // до клонирования рендер идёт через fallback engineStart в Template.
            $fs = $this->bridge->fileSystem();
            if ($site !== null && $site['theme'] !== '' && !$themes->exists($site['theme']) && $fs !== null) {
                $appView = $this->bridge->path('app_view_path');
                $starter = $appView . '/engineStart-starter';
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
        if ($views !== null && !$views->exists('home', $this->bridge->scopeApp())) {
            // Шаблон главной физически отсутствует (например, репозиторий
            // склонирован без каталога view/ или он стёрт вручную). Честная
            // диагностика вместо голого fallback-текста: пользователь должен
            // увидеть ПРИЧИНУ и путь к восстановлению, а не «белую страницу
            // с галочкой» (п.9 Конституции — самодиагностика, а не имитация).
            $hint = htmlspecialchars(
                'Каталог шаблонов view/app/engineStart/home.phtml не найден.'
                . ' Проверьте наличие папки view/ в корне движка: при клонировании'
                . ' из архива/репозитория убедитесь, что каталог view скопирован полностью,'
                . ' либо переустановите стартовый сайт engineStart.',
                ENT_QUOTES
            );
            return new Response(
                "<div style=\"font-family:system-ui,sans-serif;max-width:640px;margin:12vh auto;padding:24px;"
                . "border:1px solid #d0d7de;border-radius:8px;background:#ffffff;color:#1f2328\">"
                . "<h2 style=\"margin:0 0 12px\">⚠️ Стартовый сайт не найден</h2>"
                . "<p style=\"margin:0;line-height:1.6\">{$hint}</p></div>",
                500
            );
        }
        if ($views !== null) {
            // Статика темы — через реестр Assets (каскад site->theme->core):
            // регистрируем 'site.css'/'site.js' и публикуем теги в шаблоны.
            // Файлы темы меняются без правки layout; хук engine.assets.*
            // даёт модулям дописать своё (п.4). Если файлов нет — в каскаде
            // совпадения не будет, URL всё равно корректен (404 без ?v=),
            // а layout темы использует инлайн-fallback.
            $assets = $this->bridge->assets();
            if ($assets !== null) {
                $assets->useContext($site['domain'] ?? null, $theme !== '' ? $theme : $this->bridge->defaultTheme());
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
                ], $this->bridge->scopeApp());
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
     * Мультисайт: рекурсивно клонирует папку-стартер темы (view/app/engineStart-starter)
     * в новую тему сайта. best effort: любой сбой FS означает «папки нет»,
     * рендер уйдёт через fallback engineStart — движок не падает (принцип п.9).
     */
    private function cloneStarter(
        object $fs,
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
