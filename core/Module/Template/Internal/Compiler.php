<?php
declare(strict_types=1);

namespace App\Core\Module\Template\Internal;

use InvalidArgumentException;

/**
 * Компилятор мини-языка .phtml → чистый PHP (внутренняя кухня Template).
 *
 * Директивы:
 *   @extends('layout')  @section('name') ... @endsection  @yield('name', 'default')
 *   @include('partials/header', ['a' => 1])
 *   @if / @elseif / @else / @endif   @foreach / @endforeach   @for / @endfor
 *   Вывод: {{ $x }} (экранируется), {!! $x !!} (raw).
 *
 * Имена шаблонов нормализуются: только [A-Za-z0-9_/-], обязательно относительное,
 * без ".." — защита от path traversal.
 */
final class Compiler
{
    /** Версия синтаксиса компилятора — входит в ключ кэша (см. CacheStore). */
    public const SYNTAX_VERSION = '2';

    /** Теги PHP собраны из частей: файл компилятора не должен содержать их литералов. */
    private const TAG_OPEN = "<?php ";
    private const TAG_CLOSE = " ?>";

    /** Маркеры произвольного PHP между @php и @endphp. */
    private const RAW_PHP    = "\x01VAMPRAWPHP\x01";
    private const RAW_ENDPHP = "\x01VAMPRAWEND\x01";

    /** Кэш компиляции строки (для renderString). */
    private array $stringCache = [];

    public function compile(string $source): string
    {
        // 0. Плейсхолдерим управляющие последовательности (@directive), чтобы
        // ни один из проходов (вывод/директивы) не сканировал чужие стадии:
        // подстановки с тегами PHP больше не встретят '@section' внутри
        // себя, а email-подобный текст 'a@if' не будет съеден как директива.
        $directives = [];
        $masked = preg_replace_callback(
            '/@([a-zA-Z]+)\s*(?:\(((?:[^()]|\([^()]*\))*)\))?/',
            static function (array $m) use (&$directives): string {
                $key = '___DIR' . count($directives) . '___';
                $directives[$key] = $m[0];
                return $key;
            },
            $source
        ) ?? $source;

        // 1. Сохраняем raw-вывод {!! ... !!} заглушками, чтобы он не мешал {{ }}.
        $raws = [];
        $masked = preg_replace_callback(
            '/\{!!\s*(.+?)\s*!!\}/su',
            static function (array $m) use (&$raws): string {
                $key = '___RAW' . count($raws) . '___';
                $raws[$key] = $m[1];
                return $key;
            },
            $masked
        ) ?? $masked;

        // 2. Экранированный вывод {{ ... }}.
        $masked = preg_replace(
            '/\{\{\s*(.+?)\s*\}\}/su',
            static fn(array $m): string => self::TAG_OPEN . "echo \$this->e(" . $m[1] . self::TAG_CLOSE,
            $masked
        ) ?? $masked;

        // 3. Возврат raw-вывода.
        foreach ($raws as $key => $expr) {
            $masked = str_replace($key, self::TAG_OPEN . 'echo (' . $expr . ');' . self::TAG_CLOSE, $masked);
        }

        // 4. Управляющие директивы (разворачиваем плейсхолдеры).
        //
        // Каждая директива компилируется ровно один раз из исходного текста:
        // стадия 0 уже спрятала все вхождения '@directive(...)' в невидимые для
        // остальных проходов плейсхолдеры, поэтому подстановки с тегами PHP
        // больше не сканируются последующими правилами (прежняя схема «preg_replace
        // по очереди на каждую директиву» систематически ломала секции: аргументы
        // соседних @section сливались ленивым квантификатором и контент страницы
        // терялся — баг инсталлятора: пустые формы вместо wizard'а).
        $directiveMap = [
            'extends'    => '$this->extend({ARGS});',
            'section'    => '$this->startSection({ARGS});',
            'endsection' => '$this->stopSection();',
            'show'       => '$this->stopSection(); echo $this->yieldSection($this->lastSectionName());',
            'stop'       => '$this->stopSection();',
            'yield'      => 'echo $this->yieldSection({ARGS});',
            'include'    => 'echo $this->renderPartial({ARGS});',
            'if'         => 'if ({ARGS}):',
            'elseif'     => 'elseif ({ARGS}):',
            'else'       => 'else:',
            'endif'      => 'endif;',
            'foreach'    => 'foreach ({ARGS}):',
            'endforeach' => 'endforeach;',
            'for'        => 'for ({ARGS}):',
            'endfor'     => 'endfor;',
            'while'      => 'while ({ARGS}):',
            'endwhile'   => 'endwhile;',
            'php'        => self::RAW_PHP . '{ARGS}',
            'endphp'     => self::RAW_ENDPHP,
        ];

        $compiled = $masked;
        foreach ($directives as $key => $original) {
            if (!preg_match('/^@([a-zA-Z]+)\s*(?:\(((?:[^()]|\([^()]*\))*)\))?$/', $original, $m)) {
                continue;
            }
            $name = strtolower($m[1]);
            if (!isset($directiveMap[$name])) {
                continue; // неизвестная директива остаётся как есть (email, @media...)
            }
            $args  = isset($m[2]) && trim($m[2]) !== '' ? trim($m[2]) : '';
            $shape = $directiveMap[$name];

            if (str_contains($shape, '{ARGS}')) {
                if ($args === '') {
                    throw new InvalidArgumentException("Директивe [" . $name . "] нужны аргументы в скобках.");
                }
                $php = self::TAG_OPEN . str_replace('{ARGS}', $args, $shape) . self::TAG_CLOSE;
            } else {
                // Директивы без аргументов (@endsection, @show, @else...) могут
                // случайно встретить '(' — тогда это НЕ их аргумент: возвращаем
                // скобки обратно в HTML-текст.
                $php = self::TAG_OPEN . $shape . self::TAG_CLOSE . ($args === '' ? '' : '(' . $m[2] . ')');
            }
            $compiled = str_replace($key, $php, $compiled);
        }

        // @php ... @endphp: произвольный PHP между ними — без экранирования.
        $compiled = str_replace(self::RAW_PHP, '', $compiled);
        $compiled = str_replace(self::RAW_ENDPHP, '', $compiled);

        return $compiled;
    }

    /** Компиляция строкового шаблона с кэшем по содержимому. */
    public function compileString(string $source): string
    {
        $hash = md5($source);
        if (!isset($this->stringCache[$hash])) {
            $this->stringCache[$hash] = $this->compile($source);
        }

        return $this->stringCache[$hash];
    }

    /**
     * Нормализация имени шаблона: 'errors/404.phtml' -> 'errors/404'.
     * Падает на подозрительных именах (защита от выхода за root views).
     */
    public function normalizeName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || str_contains($name, "\0")) {
            throw new InvalidArgumentException('Пустое или недопустимое имя шаблона.');
        }

        if (str_ends_with($name, '.phtml')) {
            $name = substr($name, 0, -6);
        }

        if (!preg_match('#^[A-Za-z0-9_\-]+(/[A-Za-z0-9_\-]+)*$#', $name)) {
            throw new InvalidArgumentException("Недопустимое имя шаблона: [{$name}].");
        }

        return $name;
    }
}
