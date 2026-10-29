<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Module\Template\Internal\Compiler;
use InvalidArgumentException;
use ReflectionMethod;
use Tests\Framework\TestCase;

/**
 * Регрессия на грамматику закрытия Blade-комментариев {-- ... --}.
 *
 * Исторический дефект: сканер вырезал пару '--', но '}' оставлял в потоке —
 * на '{-- a --}}{{ $x }}' получался мусорный '}' перед выводом, а на
 * '{-- a --}}' — артефактный хвост. Теперь закрытие строго '--}' или '--}}'
 * и не оставляет символов. Тест фиксирует таблицу переходов как инвариант.
 */
final class TemplateCommentTest extends TestCase
{
    /** Вызов приватного сканера — тестируем чистую функцию вырезания. */
    private static function strip(string $source): string
    {
        $m = new ReflectionMethod(Compiler::class, 'stripComments');
        return $m->invoke(new Compiler(), $source);
    }

    public function testClosedFormsLeaveNoArtifacts(): void
    {
        $this->assertSame('text', self::strip('{-- a --}text'));                 // обычная форма
        $this->assertSame('', self::strip('{-- a --}}'));                        // Blade-форма
        $this->assertSame('{{ $x }}', self::strip('{-- a --}}{{ $x }}'));        // закрытие перед выводом
        $this->assertSame('AC', self::strip('A{-- b ----}}C'));                  // запуск дефисов: последняя пара
        $this->assertSame('12', self::strip('{-- one --}1{-- two --}2'));        // несколько подряд
        $this->assertSame('plain {{ $v }}', self::strip('plain {{ $v }}'));      // без комментариев
    }

    public function testCommentContentIsNotCompiled(): void
    {
        // директивы и вывод внутри комментария не должны попадать в PHP-код
        $compiled = (new Compiler())->compileString('{-- {{ $secret }} @if(true) --}ok');
        $this->assertTrue(!str_contains($compiled, '$secret'), 'Вывод из комментария просочился.');
        $this->assertTrue(!str_contains($compiled, 'if('), 'Директива из комментария скомпилировалась.');
        $this->assertTrue(str_contains($compiled, 'ok'), 'Текст после комментария потерян.');
    }

    public function testUnclosedCommentThrows(): void
    {
        $threw = false;
        try {
            self::strip('{-- never closed');
        } catch (InvalidArgumentException) {
            $threw = true;
        }
        $this->assertTrue($threw, 'Незакрытый комментарий обязан бросать, а не съедать хвост.');
    }

    public function testStrippingIsIdempotent(): void
    {
        $once  = self::strip('A{-- b --}C{-- d --}}E{{ $x }}');
        $twice = self::strip($once);
        $this->assertSame('ACE{{ $x }}', $once);
        $this->assertSame($once, $twice, 'Повторный прогон не должен менять результат.');
    }
}
