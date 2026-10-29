<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Module\ArrayHelper\ArrayHelper;
use Tests\Framework\TestCase;

/**
 * Тесты точечной нотации и утилит массивов (модуль нулевого уровня, п.6).
 */
final class ArrayHelperTest extends TestCase
{
    private ArrayHelper $h;

    public function __construct()
    {
        $this->h = new ArrayHelper();
    }

    public function testGetDotNotation(): void
    {
        $data = ['db' => ['mysql' => ['host' => '127.0.0.1']]];

        $this->assertSame('127.0.0.1', $this->h->get($data, 'db.mysql.host'));
        $this->assertSame('fallback', $this->h->get($data, 'db.mysql.port', 'fallback'));
        $this->assertNull($this->h->get($data, ''));
    }

    public function testHas(): void
    {
        $data = ['a' => ['b' => null]];

        $this->assertTrue($this->h->has($data, 'a.b'), 'null — значение существует.');
        $this->assertFalse($this->h->has($data, 'a.c'));
    }

    public function testSetCreatesNestedStructure(): void
    {
        $out = $this->h->set([], 'x.y.z', 42);

        $this->assertSame(['x' => ['y' => ['z' => 42]]], $out);
    }

    public function testForgetRemovesLeaf(): void
    {
        $out = $this->h->forget(['a' => ['b' => 1, 'c' => 2]], 'a.b');

        $this->assertSame(['a' => ['c' => 2]], $out);
    }

    public function testMergeDeep(): void
    {
        $left  = ['db' => ['driver' => 'sqlite', 'opts' => ['a' => 1]]];
        $right = ['db' => ['opts' => ['b' => 2]]];

        $this->assertSame(
            ['db' => ['driver' => 'sqlite', 'opts' => ['a' => 1, 'b' => 2]]],
            $this->h->mergeDeep($left, $right)
        );
    }

    public function testOnlyExcept(): void
    {
        $src = ['a' => 1, 'b' => 2, 'c' => 3];

        $this->assertSame(['a' => 1, 'c' => 3], $this->h->only($src, ['a', 'c']));
        $this->assertSame(['b' => 2], $this->h->except($src, ['a', 'c']));
    }

    public function testFirstWithCallback(): void
    {
        $this->assertSame(4, $this->h->first([1, 2, 3, 4], static fn (int $v): bool => $v > 3));
        $this->assertSame('none', $this->h->first([1], static fn (int $v): bool => false, 'none'));
    }
}
