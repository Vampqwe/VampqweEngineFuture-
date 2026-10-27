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

    /** @var string|null имя layout, объявленного через @extends */
    private ?string $layout = null;

    public function __construct(
        private readonly Compiler $compiler,
        private readonly Escaper $escaper,
        /** @var callable(string, array<string,mixed>): string рендер имени по текущему site/theme */
        private $nameRenderer,
    ) {
    }

    public function share(array $globals): void
    {
        $this->shared = $globals;
    }

    /** Точка входа: отрендерить PHP-код скомпилированного шаблона. */
    public function render(string $compiledPhp, array $data): string
    {
        $this->reset();
        $output = $this->evaluate($compiledPhp, $data);

        // Двухпроходный рендер: если шаблон объявил @extends('layout'),
        // рендерим layout, вставляя наши секции. Цикл с защитой от 10 уровней
        // наследования (layout может сам наследоваться от другого layout).
        $depth = 0;
        while ($this->layout !== null && $depth++ < 10) {
            $layoutName = $this->layout;
            $sections   = $this->sections;
            $this->reset();
            $this->sections = $sections;

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
    }

    private function evaluate(string $compiledPhp, array $data): string
    {
        $scope = array_merge($this->shared, $data);

        // eval() исполняет код в PHP-режиме: открывающий тег внутри строки
        // является синтаксической ошибкой. Компилятор выдаёт шаблон как смесь
        // inline-HTML и php-фрагментов, поэтому куски HTML между закрывающим
        // и открывающим тегами превращаются в echo; одиночные теги в начале
        // и конце строки снимаются.
        $code = preg_replace_callback(
            '/\?>\s*(.*?)\s*<\?php/s',
            static function (array $m): string {
                if ($m[1] === '') {
                    return '';
                }
                $html = str_replace(['\\', "'"], ['\\\\', "\\'"], $m[1]);
                return "echo '" . $html . "';";
            },
            $compiledPhp
        ) ?? $compiledPhp;

        $code = preg_replace('/^\s*<\?php\s*/', '', $code) ?? $code;
        $code = preg_replace('/\s*\?>\s*$/', '', $code) ?? $code;

        ob_start();
        try {
            (function () use ($code, $scope): void {
                extract($scope, EXTR_SKIP);
                eval($code);
            })();
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

    public function stopSection(): void
    {
        $name     = array_pop($this->sectionStack);
        $content  = (string) ob_get_clean();
        if ($name === null) {
            throw new RuntimeException('@endsection без @section.');
        }
        $this->sections[$name] = ($this->sections[$name] ?? '') . $content;
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
