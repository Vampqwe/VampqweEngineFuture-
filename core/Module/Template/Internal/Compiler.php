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
    /** Кэш компиляции строки (для renderString). */
    private array $stringCache = [];

    public function compile(string $source): string
    {
        // 1. Сохраняем raw-вывод {!! ... !!} заглушками, чтобы он не мешал {{ }}.
        $raws = [];
        $source = preg_replace_callback(
            '/\{!!\s*(.+?)\s*!!\}/su',
            static function (array $m) use (&$raws): string {
                $key = '___RAW' . count($raws) . '___';
                $raws[$key] = $m[1];
                return $key;
            },
            $source
        ) ?? $source;

        // 2. Экранированный вывод {{ ... }}.
        $source = preg_replace(
            '/\{\{\s*(.+?)\s*\}\}/su',
            '<?php echo $this->e($1); ?>',
            $source
        ) ?? $source;

        // 3. Возврат raw-вывода.
        foreach ($raws as $key => $expr) {
            $source = str_replace($key, '<?php echo (' . $expr . '); ?>', $source);
        }

        // 4. Управляющие директивы (порядок важен: сначала закрытия).
        $patterns = [
            '/@extends\(\s*(.+?)\s*\)/'                          => '<?php $this->extend($1); ?>',
            '/@section\(\s*(.+?)\s*\)/'                          => '<?php $this->startSection($1); ?>',
            '/@endsection/'                                      => '<?php $this->stopSection(); ?>',
            '/@yield\(\s*(.+?)\s*\)/'                            => '<?php echo $this->yieldSection($1); ?>',
            '/@include\(\s*(.+?)\s*\)/'                          => '<?php echo $this->renderPartial($1); ?>',
            '/@if\(\s*(.+?)\s*\)/'                               => '<?php if ($1): ?>',
            '/@elseif\(\s*(.+?)\s*\)/'                           => '<?php elseif ($1): ?>',
            '/@else/'                                            => '<?php else: ?>',
            '/@endif/'                                           => '<?php endif; ?>',
            '/@foreach\(\s*(.+?)\s*\)/'                          => '<?php foreach ($1): ?>',
            '/@endforeach/'                                      => '<?php endforeach; ?>',
            '/@for\(\s*(.+?)\s*\)/'                              => '<?php for ($1): ?>',
            '/@endfor/'                                          => '<?php endfor; ?>',
            '/@php/'                                             => '<?php ',
            '/@endphp/'                                          => ' ?>',
        ];
        foreach ($patterns as $pattern => $replacement) {
            $source = preg_replace($pattern, $replacement, $source) ?? $source;
        }

        return $source;
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
