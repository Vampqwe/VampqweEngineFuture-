<?php
declare(strict_types=1);

namespace App\Core\Module\Template;

use App\Core\Module\Template\Contracts\TemplateInterface;
use App\Core\Module\Template\Internal\Compiler;
use App\Core\Module\Template\Internal\TemplateEnvironment;
use InvalidArgumentException;
use RuntimeException;

/**
 * Фасад модуля Template — компилируемый PHP-шаблонизатор собственного ядра.
 *
 * Зависимости (п.6): Config (template.*); пути — из контейнера
 * ('core_view_path', 'app_view_path', 'cache_path') — п.7, никаких хардкодов.
 * Отдаёт: TemplateInterface.
 *
 * Цикл рендера: resolve -> compile (кэш по mtime) -> run -> layout (2 прохода).
 */
final class Template implements TemplateInterface
{
    /** @var array<string,mixed> */
    private array $shared = [];

    /** Базовый корень app-раздела БЕЗ темы (для переключения мультисайта). */
    private readonly string $appBase;

    /** @var array<string,string> раздел => абсолютный корень с учётом темы */
    private array $roots;

    public function __construct(
        string $coreViewPath,
        string $appViewPath,
        private readonly string $cacheDir,
        private readonly string $extension = 'phtml',
        private readonly bool $cacheEnabled = true,
        ?string $defaultTheme = null,
    ) {
        $this->appBase       = rtrim($appViewPath, '/');
        $theme               = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($defaultTheme ?? '_default')) ?: '_default';
        $this->roots['core'] = rtrim($coreViewPath, '/');
        $this->roots['app']  = $this->appBase . '/' . $theme;
    }

    public function setSite(string $theme): void
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '', $theme) ?? '';
        if ($safe === '') {
            throw new InvalidArgumentException('Некорректное имя темы сайта.');
        }
        $this->roots['app'] = $this->appBase . '/' . $safe;
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    public function exists(string $template): bool
    {
        try {
            $this->resolve($template);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function render(string $template, array $data = []): string
    {
        [$content, $extends, $sections] = $this->renderOnce($template, $data + $this->shared);

        // Двухпроходный layout: child собрал секции -> рендерим layout,
        // передавая секции как данные (@yield читает $__sections).
        if ($extends !== null) {
            $layoutName = str_contains($extends, '/') ? $extends : $this->sectionOf($template) . '/' . $extends;
            return $this->renderOnce($layoutName, $sections + $this->shared)[0];
        }

        return $content;
    }

    public function renderString(string $contents, array $data = []): string
    {
        $code = (new Compiler())->compile($contents);
        $tmp  = tempnam(sys_get_temp_dir(), 'vqwe_str_') ?: '';
        file_put_contents($tmp, $code);
        try {
            [$out] = $this->evaluateFile($tmp, $data + $this->shared, fn(): string => '');
        } finally {
            @unlink($tmp);
        }
        return $out;
    }

    public function clearCache(): int
    {
        if ($this->cacheDir === '' || !is_dir($this->cacheDir)) {
            return 0;
        }
        $n = 0;
        foreach ((array) glob(rtrim($this->cacheDir, '/') . '/*.tpl.php') as $f) {
            if (is_file($f) && unlink($f)) {
                $n++;
            }
        }
        return $n;
    }

    // --- внутреннее ---------------------------------------------------------

    /** @return array{0:string,1:?string,2:array<string,string>} */
    private function renderOnce(string $name, array $data): array
    {
        $file     = $this->resolve($name);
        $source   = (string) file_get_contents($file);
        $compiled = $this->cacheWrite($file, (new Compiler())->compile($source));

        $inc = fn(string $sub, array $subData): string => $this->render(
            str_contains($sub, '/') ? $sub : $this->dirOf($name) . '/' . $sub,
            $subData
        );

        return $this->evaluateFile($compiled, $data, $inc);
    }

    /**
     * Инкапсулированное выполнение скомпилированного файла.
     * Единая переменная $__tpl держит окружение; данные шаблона — локальные переменные scope.
     *
     * @return array{0:string,1:?string,2:array<string,string>} content, extends, sections
     */
    private function evaluateFile(string $compiledPath, array $data, \Closure $inc): array
    {
        $env     = new TemplateEnvironment();
        $runtime = new class ($env, $inc, $data) {
            public function __construct(
                private object $env,
                private \Closure $inc,
                private array $data,
            ) {
            }

            /** @return array{0:string,1:?string} [content, extends] */
            public function run(string $__path): array
            {
                $__tpl = [
                    'env'      => $this->env,
                    'inc'      => $this->inc,
                    'sections' => [],
                    'extends'  => null,
                    'data'     => $this->data,
                ];
                extract($this->data, EXTR_SKIP);

                ob_start();
                require $__path;
                $content = (string) ob_get_clean();

                return [$content, $__tpl['extends']];
            }
        };

        try {
            [$content, $extends] = $runtime->run($compiledPath);
        } catch (\Throwable $e) {
            throw $e;
        }

        return [$content, $extends, $env->sections()];
    }

    /** Полный путь к исходнику с фолбэком темы app -> _default. */
    private function resolve(string $name): string
    {
        if (!preg_match('#^(core|app)/[A-Za-z0-9_\-/.]+$#', $name)) {
            throw new InvalidArgumentException("Некорректное имя шаблона «{$name}»: ожидается «core/...» или «app/...».");
        }

        $section    = explode('/', $name, 2)[0];
        $rel        = substr($name, strlen($section) + 1);
        $candidates = [$this->roots[$section] . '/' . $rel . '.' . $this->extension];

        if ($section === 'app' && !str_ends_with($this->roots['app'], '/_default')) {
            $candidates[] = $this->appBase . '/_default/' . $rel . '.' . $this->extension;
        }

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        throw new RuntimeException('Шаблон не найден: ' . $name);
    }

    /** Запись/получение скомпилированного файла (ленивая перекомпиляция по mtime). */
    private function cacheWrite(string $sourceFile, string $code): string
    {
        if ($this->cacheEnabled && $this->cacheDir !== '') {
            if (!is_dir($this->cacheDir)) {
                mkdir($this->cacheDir, 0775, true);
            }
            $compiled = rtrim($this->cacheDir, '/') . '/' . sha1($sourceFile) . '.tpl.php';
            if (!is_file($compiled) || filemtime($compiled) < filemtime($sourceFile)) {
                file_put_contents($compiled, "<?php /* compiled from {$sourceFile} */ ?>" . "\n" . $code);
            }
            return $compiled;
        }

        $tmp = sys_get_temp_dir() . '/vqwe_tpl_' . sha1($sourceFile . '|' . microtime(true)) . '.php';
        file_put_contents($tmp, $code);
        return $tmp;
    }

    private function sectionOf(string $name): string
    {
        return explode('/', $name, 2)[0];
    }

    /** Каталог шаблона (для относительных @include). */
    private function dirOf(string $name): string
    {
        $pos = strrpos($name, '/');
        return $pos === false ? $name : substr($name, 0, $pos);
    }
}
