<?php
declare(strict_types=1);

namespace App\Core\Module\Template\Internal;

use RuntimeException;

/**
 * Среда исполнения скомпилированного шаблона (внутренняя кухня Template).
 *
 * Рендеринг происходит внутри этого класса (extract данных), поэтому
 * шаблоны НЕ имеют доступа к локальным переменным Kernel/модулей — только
 * к явно переданному массиву $data и глобалам share(). Это инкапсуляция п.2.
 */
final class TemplateEnvironment
{
    /** @var array<string,mixed> глобальные переменные share() */
    private array $shared = [];

    /** @var array<string,string> захваченные секции */
    private array $sections = [];

    /** @var string[] стек открытых секций */
    private array $sectionStack = [];

    /** @var string[] имена последних закрытых секций (для @show без аргумента) */
    private array $lastSectionNames = [];

    /** @var string|null имя layout, объявленного через @extends */
    private ?string $layout = null;

    /**
     * Флаг вложенного окружения: true для env, созданных childEnv() (@include).
     * Такие окружения НЕ сбрасывают унаследованные секции при render().
     */
    private bool $isNested = false;

    public function __construct(
        private readonly Compiler $compiler,
        private readonly Escaper $escaper,
        /** @var callable(string, array<string,mixed>): string рендер имени по текущему site/theme */
        private $nameRenderer,
    ) {
    }

    /**
     * Создать дочернее окружение для @include: копия секций и данных, но
     * СВОЙ layout — partial со случайным @extends не утянет родителя в цикл.
     */
    public function childEnv(): self
    {
        $child = new self($this->compiler, $this->escaper, $this->nameRenderer);
        $child->share($this->shared);
        $child->sections  = $this->sections;
        $child->isNested  = true;
        $child->fileLocator         = $this->fileLocator;
        $child->compileFileDelegate = $this->compileFileDelegate;

        return $child;
    }

    /**
     * Создать окружение рендера layout-а: наследует СЕКЦИИ родителя (иначе
     * @yield('content') теряет контент дочернего шаблона), но НЕ его layout.
     */
    public function layoutEnv(): self
    {
        return $this->childEnv();
    }

    public function share(array $globals): void
    {
        $this->shared = $globals;
    }

    /** Точка входа: отрендерить PHP-код скомпилированного шаблона. */
    public function render(string $compiledPhp, array $data): string
    {
        // Вложенные рендеры (@include partial-ов через childEnv) наследуют
        // секции родителя — их нельзя сбрасывать, иначе @yield в layout
        // потеряет контент дочернего шаблона (баг инсталлятора: пустые
        // страницы, видны только заголовки шагов).
        if (!$this->isNested) {
            $this->reset();
        }

        $output = $this->evaluate($compiledPhp, $data);

        // Дочерний шаблон мог ничего не вывести «в тело» вне секций — тогда
        // выводом первого прохода и является content (поддержка @show-стиля).
        if ($this->layout !== null && $this->sections === [] && trim($output) !== '') {
            $this->sections['content'] = $output;
        }

        // Двухпроходный рендер @extends: если шаблон объявил layout,
        // рендерим layout, вставляя наши секции. Layout исполняется в
        // ДОЧЕРНЕМ env, который наследует СЕКЦИИ этого окружения — поэтому
        // @yield('content') видит контент дочернего шаблона. Цикл с защитой
        // от 10 уровней наследования (layout может сам наследоваться).
        // (Прежняя схема «nameRenderer -> новый env без секций» теряла весь
        // контент @section-блоков: баг пустых страниц установщика.)
        $depth = 0;
        while ($this->layout !== null && $depth++ < 10) {
            $layoutName = $this->layout;
            $this->layout = null;

            $layoutFile = $this->locateFile($layoutName);
            $layoutPhp  = $this->compileFile($layoutFile);

            $env        = $this->layoutEnv();
            $output     = $env->render($layoutPhp, $data);
            $this->sections = $env->sections;   // секции переживают многоуровневый @extends
        }

        return $output;
    }

    /** Резолв имени шаблона в путь файла делегатом Template (fileLocator). */
    private function locateFile(string $name): string
    {
        /** @var callable(string): string $locator — передаётся Template::environment() */
        $locator = $this->fileLocator;

        return $locator($name);
    }

    /** @var callable(string): string резолвер имени -> абсолютный путь файла */
    private $fileLocator;

    public function setFileLocator(callable $locator): void
    {
        $this->fileLocator = $locator;
    }

    /** Компиляция файла шаблона (с дисковым кэшем) — делегат Template. */
    private function compileFile(string $file): string
    {
        /** @var callable(string): string $compilerDelegate — передаётся Template::environment() */
        $compilerDelegate = $this->compileFileDelegate;

        return $compilerDelegate($file);
    }

    /** @var callable(string): string компилятор "путь файла -> PHP-код" */
    private $compileFileDelegate;

    public function setCompileFile(callable $delegate): void
    {
        $this->compileFileDelegate = $delegate;
    }

    private function reset(): void
    {
        $this->sections      = [];
        $this->sectionStack  = [];
        $this->layout        = null;
    }

    private function evaluate(string $compiledPhp, array $data): string
    {
        $scope = array_merge($this->shared, $data);

        // Скомпилированный шаблон — это полноценный PHP-файл (смесь inline-HTML
        // и открывающих/закрывающих PHP-тегов). Исполняем его через include временного файла:
        // в отличие от eval() здесь корректно обрабатываются переходы
        // HTML->PHP режимы (в т.ч. шаблоны, начинающиеся с чистого HTML),
        // стектрейсы ошибок указывают на реальный файл, а не «eval()'d code».
        $tmp = tempnam(sys_get_temp_dir(), 'vampview') . '.php';
        file_put_contents($tmp, $compiledPhp);

        // include внутри метода: шаблон получает $scope и имеет доступ к $this
        // (методы e(), yieldSection(), renderPartial() вызываются как $this->...).
        try {
            $output = $this->renderFile($tmp, $scope);
        } finally {
            @unlink($tmp);
        }

        return $output;
    }

    private function renderFile(string $__file__, array $scope): string
    {
        extract($scope, EXTR_SKIP);
        ob_start();
        try {
            /** @psalm-suppress UnresolvableInclude — скомпилированный шаблон */
            include $__file__;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }

    // ==== методы, которые вызывает скомпилированный код (через $this) ====

    public function e(mixed $value): string
    {
        return $this->escaper->escape($value);
    }

    public function extend(string $name): void
    {
        $this->layout = $name;
    }

    public function startSection(string $name): void
    {
        $this->sectionStack[] = $name;
        ob_start();
    }

    /** Имя последней закрытой секции (@show без аргумента). */
    public function lastSectionName(): string
    {
        return (string) ($this->lastSectionNames[count($this->lastSectionNames) - 1] ?? '');
    }

    public function stopSection(): void
    {
        $name     = array_pop($this->sectionStack);
        $content  = (string) ob_get_clean();
        if ($name === null) {
            throw new RuntimeException('@endsection без @section.');
        }
        $this->sections[$name] = ($this->sections[$name] ?? '') . $content;
        $this->lastSectionNames[] = $name;
    }

    public function yieldSection(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    /** @include('partials/header', ['a' => 1]) */
    public function renderPartial(string $name, array $data = []): string
    {
        return ($this->nameRenderer)($name, $data);
    }
}
