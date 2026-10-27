<?php
declare(strict_types=1);

namespace App\Core\Module\Template;

use App\Core\Module\Template\Contracts\TemplateInterface;
use App\Core\Module\Template\Internal\CacheStore;
use App\Core\Module\Template\Internal\Compiler;
use App\Core\Module\Template\Internal\Escaper;
use App\Core\Module\Template\Internal\TemplateEnvironment;
use InvalidArgumentException;
use RuntimeException;

/**
 * Фасад модуля Template — компилируемый PHP-шаблонизатор без внешних зависимостей.
 *
 * Два раздела views (единая точка правды — Paths):
 *   view/core/          SCOPE_CORE — шаблоны ядра: ошибки, installer, админка;
 *   view/app/<theme>/   SCOPE_APP  — пользовательские темы сайтов (мультисайт).
 *
 * Мультисайт: Kernel при обработке запроса вызывает setTheme(тема_сайта)
 * (тема берётся из БД: домен -> theme). Если шаблона в теме нет — fallback
 * на '_default', затем — ошибка с понятным текстом.
 *
 * Кэш компиляции: storage/cache/templates/<md5(путь)>_<mtime>.php —
 * инвалидация по mtime исходника автоматическая.
 */
final class Template implements TemplateInterface
{
    public const DEFAULT_THEME = '_default';

    private Compiler $compiler;
    private Escaper $escaper;
    private CacheStore $cache;

    /** @var array<string,mixed> */
    private array $shared = [];

    private string $theme = self::DEFAULT_THEME;

    /**
     * @param string $coreViewPath абсолютный путь view/core (из контейнера 'core_view_path')
     * @param string $appViewPath  абсолютный путь view/app  (из контейнера 'app_view_path')
     * @param string $cachePath    абсолютный путь кэша (Paths 'cache' + '/templates')
     */
    public function __construct(
        private readonly string $coreViewPath,
        private readonly string $appViewPath,
        private readonly string $cachePath,
        bool $cacheEnabled = true,
    ) {
        $this->compiler = new Compiler();
        $this->escaper  = new Escaper();
        $this->cache    = new CacheStore($cachePath, $cacheEnabled);
    }

    public function render(string $template, array $data = [], string $scope = self::SCOPE_APP): string
    {
        if ($scope === self::SCOPE_CORE) {
            return $this->renderFromRoot($this->coreViewPath, $template, $data);
        }

        if ($scope !== self::SCOPE_APP) {
            throw new InvalidArgumentException("Неизвестный scope шаблона: [{$scope}].");
        }

        // Мультисайт: тема сайта -> fallback '_default'.
        $file = $this->locateInApp($template);
        if ($file === null) {
            throw new RuntimeException(sprintf(
                'Шаблон [%s] не найден в теме "%s" (view/app/%s/) и в fallback-теме "%s".',
                $template, $this->theme, $this->theme, self::DEFAULT_THEME
            ));
        }

        return $this->renderFile($file, $data);
    }

    public function renderCore(string $template, array $data = []): string
    {
        return $this->render($template, $data, self::SCOPE_CORE);
    }

    public function renderString(string $contents, array $data = []): string
    {
        $env = $this->environment(fn(string $name, array $extra): string
            => $this->render($name, $extra)); // строковый шаблон тоже может @include

        return $env->render($this->compiler->compileString($contents), $data);
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    public function exists(string $template, string $scope = self::SCOPE_APP): bool
    {
        $name = $this->compiler->normalizeName($template);

        return match ($scope) {
            self::SCOPE_CORE => is_file($this->coreViewPath . '/' . $name . '.phtml'),
            default          => $this->locateInApp($name) !== null,
        };
    }

    public function setTheme(string $theme): void
    {
        // Защита от path traversal: тема — только безопасное имя папки.
        if (!preg_match('#^[A-Za-z0-9_\-]+$#', $theme)) {
            throw new InvalidArgumentException("Недопустимое имя темы: [{$theme}].");
        }
        $this->theme = $theme;
    }

    public function theme(): string
    {
        return $this->theme;
    }

    public function clearCache(): int
    {
        return $this->cache->clearAll();
    }

    // ================================ внутреннее ================================

    /** Поиск файла шаблона в app-разделе: активная тема, затем _default. */
    private function locateInApp(string $name): ?string
    {
        $name = $this->compiler->normalizeName($name);

        $candidate = $this->appViewPath . '/' . $this->theme . '/' . $name . '.phtml';
        if (is_file($candidate)) {
            return $candidate;
        }

        if ($this->theme !== self::DEFAULT_THEME) {
            $fallback = $this->appViewPath . '/' . self::DEFAULT_THEME . '/' . $name . '.phtml';
            if (is_file($fallback)) {
                return $fallback;
            }
        }

        return null;
    }

    private function renderFromRoot(string $root, string $template, array $data): string
    {
        $name = $this->compiler->normalizeName($template);
        $file = $root . '/' . $name . '.phtml';
        if (!is_file($file)) {
            throw new RuntimeException("Шаблон ядра [{$name}] не найден: {$file}");
        }

        return $this->renderFile($file, $data);
    }

    /** Скомпилировать (с дисковым кэшем) и выполнить файл шаблона. */
    private function renderFile(string $file, array $data): string
    {
        $compiled = $this->cache->get($file);
        if ($compiled === null) {
            $source = file_get_contents($file);
            if ($source === false) {
                throw new RuntimeException("Не удалось прочитать шаблон: {$file}");
            }
            $compiled = $this->compiler->compile($source);
            $this->cache->put($file, $compiled);
        }

        // Ядро шаблонов резолвит @include/@extends относительно того же корня,
        // из которого пришёл файл (core-шаблоны include-ят core, app — app).
        $inCore = str_starts_with(realpath($file) ?: $file, (string) realpath($this->coreViewPath));

        $env = $this->environment(function (string $name, array $extra) use ($inCore, $data): string {
            $merged = array_merge($data, $extra);
            return $inCore
                ? $this->renderFromRoot($this->coreViewPath, $name, $merged)
                : $this->render($name, $merged, self::SCOPE_APP);
        });

        return $env->render($compiled, $data);
    }

    private function environment(callable $nameRenderer): TemplateEnvironment
    {
        $env = new TemplateEnvironment($this->compiler, $this->escaper, $nameRenderer);
        $env->share($this->shared);
        return $env;
    }
}
