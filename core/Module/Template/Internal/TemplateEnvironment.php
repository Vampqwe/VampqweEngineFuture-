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

    /** @var array<string,string> секции, переданные из дочернего шаблона в layout */
    private array $inheritedSections = [];

    /**
     * Разделитель вызовов: true — этот env является «родительским» (тот, что
     * объявил @extends). Дочерние env (создаваемые Template::renderFile для
     * layout/partial) получают его равным false и обязаны вернуть захваченные
     * секции наверх через pullSections() — иначе @yield в layout теряет контент.
     */
    private bool $isRoot;

    public function __construct(
        private readonly Compiler $compiler,
        private readonly Escaper $escaper,
        /** @var callable(string, array<string,mixed>): string рендер имени по текущему site/theme */
        private $nameRenderer,
        bool $isRoot = true,
    ) {
        $this->isRoot = $isRoot;
    }

    /**
     * Секции, захваченные этим окружением (для переноса родителю после рендера
     * layout/partial). Вызывается Template::renderFile после env->render().
     *
     * @return array<string,string>
     */
    public function pullSections(): array
    {
        return $this->sections;
    }

    public function share(array $globals): void
    {
        $this->shared = $globals;
    }

    /** Точка входа: отрендерить PHP-код скомпилированного шаблона. */
    public function render(string $compiledPhp, array $data): string
    {
        // Вложенные окружения (layout/partial, создаваемые Template::renderFile)
        // наследуют секции родителя — их нельзя сбрасывать, иначе @yield в layout
        // потеряет контент дочернего шаблона (баг инсталлятора: пустые страницы).
        if ($this->isRoot) {
            $this->reset();
        } else {
            $this->sections = array_merge($this->inheritedSections, $this->sections);
        }

        $output = $this->evaluate($compiledPhp, $data);

        // Если этот env НЕ корневой (его создал Template::renderFile для
        // layout/partial), то layout уже отрендерился внутри evaluate() через
        // рекурсивный вызов nameRenderer -> render(). В этом случае секции,
        // захваченные здесь, должны быть переданы родителю через pullSections().
        if (!$this->isRoot) {
            return $output;
        }

        // Корневой env: обрабатываем @extends двухпроходным рендером.
        // Двухпроходный рендер: если шаблон объявил @extends('layout'),
        // рендерим layout, вставляя наши секции. Цикл с защитой от 10 уровней
        // наследования (layout может сам наследоваться от другого layout).
        // Ключевой момент: дочерний шаблон мог ничего не вывести «в тело»
        // (@yield пустой) — тогда выводом первого прохода и является content.
        if ($this->layout !== null && $this->sections === [] && trim($output) !== '') {
            $this->sections['content'] = $output;
        }

        $depth = 0;
        while ($this->layout !== null && $depth++ < 10) {
            $layoutName = $this->layout;
            // Секции наследуемого шаблона переносятся в layout: они не должны
            // теряться при сбросе окружения (иначе @yield отдаст дефолт).
            $inherited  = array_merge($this->inheritedSections, $this->sections);
            $this->reset();
            $this->inheritedSections = $inherited;
            $this->sections          = $inherited;

            // Layout рендерится ВНУТРИ нового env (как partial), чтобы его @yield
            // видел секции, которые мы передали выше. Но так как layout не должен
            // создавать свой layout-цикл (он сам layout), we pass isRoot=false.
            $layoutPhp = ($this->nameRenderer)($layoutName, []);
            $output    = $this->evaluate($layoutPhp, $data);
        }

        return $output;
    }

    private function reset(): void
    {
        $this->sections      = [];
        $this->sectionStack  = [];
        $this->layout        = null;
        $this->inheritedSections = [];
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
