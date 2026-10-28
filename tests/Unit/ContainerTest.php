<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\System\Container\Container;
use RuntimeException;
use Tests\Framework\TestCase;

/**
 * DI-контейнер: bind/instance/get, autowiring по конструктору,
 * синглтон-семантика и защита от циклических зависимостей (п.3 Конституции).
 */
final class ContainerTest extends TestCase
{
    public function testInstanceAndGet(): void
    {
        $c = new Container();
        $obj = new \stdClass();
        $c->instance('marker', $obj);
        $this->assertSame($obj, $c->get('marker'));
    }

    public function testBindIsLazySingleton(): void
    {
        $c = new Container();
        $calls = 0;
        $c->bind('svc', function () use (&$calls): \stdClass {
            $calls++;
            return new \stdClass();
        });

        $this->assertSame(0, $calls, 'Фабрика не должна вызываться до get()');
        $a = $c->get('svc');
        $b = $c->get('svc');
        $this->assertSame(1, $calls, 'Фабрика вызывается ровно один раз (синглтон)');
        $this->assertSame($a, $b);
    }

    public function testAutowiringResolvesConstructorDependencies(): void
    {
        $c = new Container();
        $dep = new \stdClass();
        $c->instance(\stdClass::class, $dep);

        $auto = $c->get(AutowireTarget::class);
        $this->assertTrue($auto instanceof AutowireTarget);
        $this->assertSame($dep, $auto->dep, 'Зависимость должна быть подтянута из контейнера');
    }

    public function testCyclicDependencyThrows(): void
    {
        $c = new Container();
        $c->bind('a', fn(Container $ctr): string => $ctr->get('b') . 'a');
        $c->bind('b', fn(Container $ctr): string => $ctr->get('a') . 'b');

        $this->expectException(RuntimeException::class, fn() => $c->get('a'), 'Циклическая зависимость');
    }

    public function testUnknownServiceThrows(): void
    {
        $c = new Container();
        $this->expectException(\Throwable::class, fn() => $c->get('nope/not-a-class'));
    }
}

/** Класс-мишень для проверки автовайринга (не тест, префикс test* не мешает — методный фильтр). */
final class AutowireTarget
{
    public function __construct(public readonly \stdClass $dep)
    {
    }
}
