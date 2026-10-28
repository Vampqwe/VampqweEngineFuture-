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
 *   @while / @endwhile   @php ... @endphp
 *   Вывод: {{ $x }} (экранируется), {!! $x !!} (raw).
 *
 * Синтаксис компиляции — ОДНОПРОХОДНЫЙ (см. compile()): исходный текст
 * сканируется единым матчером токенов, а не цепочкой preg_replace по
 * директивам. Это исключает класс багов «аргументы соседних секций
 * слились ленивым квантификатором» и потерю контента @yield.
 *
 * Имена шаблонов нормализуются: только [A-Za-z0-9_/-], обязательно относительное,
 * без ".." — защита от path traversal.
 */
final class Compiler
{
    /** Версия синтаксиса компилятора — входит в ключ кэша (см. CacheStore). */
    public const SYNTAX_VERSION = '3';

    /** Директивы без аргументов: раскрываются в PHP-код целиком. */
    private const NO_ARG = [
        'endsection' => '$this->stopSection();',
        'stop'       => '$this->stopSection();',
        'show'       => '$this->stopSection(); echo $this->yieldSection($this->lastSectionName());',
        'else'       => 'else:',
        'endif'      => 'endif;',
        'endforeach' => 'endforeach;',
        'endfor'     => 'endfor;',
        'endwhile'   => 'endwhile;',
    ];

    /** Директивы «аргумент в скобках → PHP-фрагмент с {ARGS}». */
    private const WITH_ARG = [
        'extends'  => '$this->extend({ARGS});',
        'section'  => '$this->startSection({ARGS});',
        'yield'    => 'echo $this->yieldSection({ARGS});',
        'include'  => 'echo $this->renderPartial({ARGS});',
        'if'       => 'if ({ARGS}):',
        'elseif'   => 'elseif ({ARGS}):',
        'foreach'  => 'foreach ({ARGS}):',
        'for'      => 'for ({ARGS}):',
        'while'    => 'while ({ARGS}):',
    ];

    /** Теги PHP собраны из частей: файл компилятора не должен содержать их литералов. */
    private const TAG_OPEN = '<?php ';
    private const TAG_CLOSE = ' ?>';

    /** Маркер произвольного PHP между @php и @endphp. */
    private const RAW_PHP    = '<?p' . 'hp ' . "\x01VAMPRAWPHP\x01";
    private const RAW_ENDPHP = "\x01VAMPRAWEND\x01" . ' ?' . '>';

    /** @var array<string,string> кэш компиляции строки (для renderString) */
    private array $stringCache = [];

    /**
     * Однопроходная компиляция: единый матчер токенов шаблонизирует исходник
     * слева направо. Каждый токен (вывод, директива, текст) обрабатывается
     * ровно один раз на своей позиции — никаких повторных сканов по уже
     * подставленному PHP (именно они теряли секции в прежней схеме).
     */
    public function compile(string $source): string
    {
        // Единый матчер токенов. Порядок альтернатив важен: {!! ... !!} ДО
        // {{ ... }} (иначе '{!!' матчится как '{{' с мусором внутри).
        // Директива матчится только когда сразу за именем НЕ идёт '.' или
        // слово-символ: '@if.com' (email), '@media print'... — нет, '@media'
        // это CSS at-rule: она неизвестная и остаётся текстом; но 'mail@a@if.com'
        // не должен резаться как '@if'. Скобки — необязательная часть токена,
        // требование аргумента проверяет compileToken по имени директивы.
        $tokenRe = '/('
            . '\{!!\s*.+?\s*!!\}'          // raw-вывод
            . '|\{\{[^\r\n]*?\}\}'         // экранированный вывод (в одну строке-паре)
            . '|@php\b(?!\w)(?<!\.)'         // блок произвольного PHP
            . '|@endphp\b(?!\w)(?<!\.)'       // конец блока PHP
            . '|@[a-zA-Z]+(?:\s*\(((?:[^()]|\([^()]*\))*)\))?(?![.\w])' // директива + опц. аргумент
            . ')/su';

        return $this->finalizeRawPhp(
            preg_replace_callback(
                $tokenRe,
                fn(array $m): string => $this->compileToken($m[0]),
                $source
            ) ?? $source
        );
    }

    /**
     * Балансировка @php ... @endphp: между маркерами произвольный PHP идёт
     * как есть (в HTML-режиме включается через открывающий тег маркера), а
     * после @endphp возвращаемся в HTML закрывающим тегом. Маркеры собраны
     * из частей, чтобы этот файл не содержал литералов тегов PHP.
     */
    private function finalizeRawPhp(string $compiled): string
    {
        // 1. Закрывающий маркер -> закрывающий тег PHP.
        $out = str_replace(self::RAW_ENDPHP, self::TAG_CLOSE, $compiled);
        // 2. Открывающий маркер (открывающий тег + якорь) -> просто
        //    открывающий тег: удаляем якорь, тег уже стоит в константе.
        $out = str_replace("\x01VAMPRAWPHP\x01", '', $out);
        return $out;
    }

    /** Раскрытие одного токена шаблона в PHP/текст. */
    private function compileToken(string $token): string
    {
        // 1. {!! raw !!}
        if (preg_match('/^\{!!\s*(.+?)\s*!!\}$/su', $token, $m) === 1) {
            return self::TAG_OPEN . 'echo (' . $m[1] . ');' . self::TAG_CLOSE;
        }

        // 2. {{ escaped }}
        if (preg_match('/^\{\{\s*(.+?)\s*\}\}$/su', $token, $m) === 1) {
            return self::TAG_OPEN . 'echo $this->e(' . $m[1] . ');' . self::TAG_CLOSE;
        }

        // 3. @php / @endphp — маркеры блоков (содержимое между ними остаётся as-is)
        if (strcasecmp($token, '@php') === 0) {
            return self::RAW_PHP;
        }
        if (strcasecmp($token, '@endphp') === 0) {
            return self::RAW_ENDPHP;
        }

        // 4. Директивы: единый разбор '@name' + необязательные '(args)'
        if ($token[0] === '@' && preg_match('/^@([a-zA-Z]+)(?:\s*\(((?:[^()]|\([^()]*\))*)\))?$/s', $token, $m) === 1) {
            $name = strtolower($m[1]);
            $hasArgs = isset($m[2]) && $m[2] !== '';
            $args = $hasArgs ? trim($m[2]) : '';

            if (isset(self::WITH_ARG[$name])) {
                if (!$hasArgs || $args === '') {
                    throw new InvalidArgumentException("Директивe [{$name}] нужны аргументы в скобках.");
                }
                return self::TAG_OPEN . str_replace('{ARGS}', $args, self::WITH_ARG[$name]) . self::TAG_CLOSE;
            }

            if (isset(self::NO_ARG[$name])) {
                // Случайно прилипшие скобки (@endif(x)) — не аргумент директивы:
                // раскрываем её, скобки возвращаем текстом.
                return self::TAG_OPEN . self::NO_ARG[$name] . self::TAG_CLOSE . ($hasArgs ? '(' . $m[2] . ')' : '');
            }

            // Неизвестная (@media, @foo(bar), email-хвосты) — остаётся как есть.
        }

        return $token;
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
