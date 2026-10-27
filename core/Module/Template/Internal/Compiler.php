<?php
declare(strict_types=1);

namespace App\Core\Module\Template\Internal;

/**
 * Внутренний компилятор мини-языка .phtml -> нативный PHP (п.2: только изнутри модуля).
 *
 * Директивы фундамента:
 *   {{ $x }}                    — экранированный вывод
 *   {!! $x !!}                  — raw-вывод (осознанно!)
 *   @if(cond) / @elseif(cond) / @else / @endif
 *   @foreach(items as $i) / @endforeach
 *   @for(...) / @endfor
 *   @extends('layout')          — наследование (двухпроходный рендер в Template)
 *   @section('name') ... @endsection
 *   @yield('name'[, 'default']) — вставка секции (layout читает из $__sections)
 *   @include('partial'[, data]) — частичный шаблон ($__inc замыкает резолвер)
 */
final class Compiler
{
    public function compile(string $source): string
    {
        // Секции: тело остаётся на месте, рантайм захватит его через
        // startSection()/stopSection() (output buffering).
        $out = (string) preg_replace_callback(
            '/@section\(\s*[\'"]([^\'"]+)[\'"]\s*\)(.*?)@endsection/s',
            static fn(array $m): string =>
                "<?php \$__tpl['env']->startSection('" . addslashes($m[1]) . "'); ?>" . $m[2] . "<?php \$__tpl['env']->stopSection(); ?>",
            $source
        );

        // @extends -> присваивание: Template прочитает $extends и сделает 2-й проход.
        $out = (string) preg_replace('/@extends\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
            '<?php $__tpl[\'extends\'] = \'$1\'; ?>', $out);

        // @yield -> чтение из переданных секций.
        $out = (string) preg_replace('/@yield\(\s*[\'"]([^\'"]+)[\'"]\s*(?:,\s*((?:[^(),]|\([^()]*\))*))?\)/',
            '<?php echo $__tpl[\'sections\'][\'$1\'] ?? ($2 ?? \'\'); ?>', $out);

        // @include: относительное имя ('partial') резолвится от текущего шаблона;
        // абсолютное ('core/x'|'app/x') — как есть. Данные: общие + локальные.
        $out = (string) preg_replace_callback(
            '/@include\(\s*[\'"]([^\'"]+)[\'"]\s*(?:,\s*(\[[^\]]*\]|\$[A-Za-z_][A-Za-z0-9_]*))?\)/',
            static function (array $m): string {
                $extra = ($m[2] ?? '') !== '' ? $m[2] : '[]';
                $name  = addslashes($m[1]);
                return "<?php echo \$__tpl['inc']('{$name}', array_merge(\$__tpl['data'], {$extra})); ?>";
            },
            $out
        );

        // Порядок замен важен: сначала длинные формы (@elseif перед @else,
        // @endforeach/@endfor перед @foreach/@for), потом короткие.
        $pairs = [
            '@elseif('    => '<?php elseif(',
            '@endforeach' => '<?php endforeach; ?>',
            '@endfor'     => '<?php endfor; ?>',
            '@foreach('   => '<?php foreach(',
            '@for('       => '<?php for(',
            '@if('        => '<?php if(',
            '@endif'      => '<?php endif; ?>',
            '@else'       => '<?php else:',
        ];
        foreach ($pairs as $from => $to) {
            $out = str_replace($from, $to, $out);
        }

        // Вывод: сначала raw {!! !!}, потом экранированный {{ }}.
        $out = (string) preg_replace('/\{!!\s*(.+?)\s*!!\}/s', '<?php echo $1; ?>', $out);
        $out = (string) preg_replace('/\{\{\s*(.+?)\s*\}\}/s',
            '<?php echo \\App\\Core\\Module\\Template\\Internal\\Escaper::html($1); ?>', $out);

        return $out;
    }
}
