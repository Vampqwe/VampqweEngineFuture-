<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Module\Hook\Hook;
use Tests\Framework\TestCase;

/**
 * Тесты хук-шины (п.4 Конституции): приоритеты, фильтры, изоляция падений.
 */
final class HookTest extends TestCase
{
    public function testActionsRunByPriority(): void
    {
        $hook = new Hook();
        $log  = [];

        $hook->addAction('init', static function () use (&$log): void { $log[] = 'low'; }, 20);
        $hook->addAction('init', static function () use (&$log): void { $log[] = 'high'; }, 1);
        $hook->addAction('init', static function () use (&$log): void { $log[] = 'mid'; }, 10);

        $hook->dispatch('init');

        $this->assertSame(['high', 'mid', 'low'], $log, 'Порядок по возрастанию приоритета.');
    }

    public function testSamePriorityKeepsRegistrationOrder(): void
    {
        $hook = new Hook();
        $log  = [];

        $hook->addAction('x', static function () use (&$log): void { $log[] = 1; }, 10);
        $hook->addAction('x', static function () use (&$log): void { $log[] = 2; }, 10);

        $hook->dispatch('x');

        $this->assertSame([1, 2], $log, 'Stable sort: равные приоритеты — по порядку регистрации.');
    }

    public function testApplyFiltersChainsValues(): void
    {
        $hook = new Hook();
        $hook->addAction('title.filter', static fn (mixed $v): string => $v . '-B', 10);
        $hook->addAction('title.filter', static fn (mixed $v): string => strtoupper((string) $v), 5);

        $result = $hook->applyFilters('title.filter', 'a');

        $this->assertSame('A-B', $result, 'Фильтр с меньшим числом приоритета выполняется раньше.');
    }

    public function testFailingListenerDoesNotBreakChain(): void
    {
        $hook = new Hook();
        $reported = [];
        $hook->setErrorSink(static function (string $msg, array $ctx) use (&$reported): void {
            $reported[] = $ctx['hook'] ?? '';
        });

        $hook->addAction('f', static fn (mixed $v): string => throw new \RuntimeException('boom'), 1);
        $hook->addAction('f', static fn (mixed $v): string => $v . '+ok', 2);

        $result = $hook->applyFilters('f', 'x');

        $this->assertSame('x+ok', $result, 'Упавший слушатель не ломает цепочку (п.9).');
        $this->assertSame(['f'], $reported, 'Сбой слушателя уходит в error-sink.');
    }

    public function testRemoveById(): void
    {
        $hook = new Hook();
        $hit  = 0;
        $hook->addAction('e', static function () use (&$hit): void { $hit++; }, 10, 'guarded');

        $this->assertTrue($hook->removeAction('e', 'guarded'));
        $hook->dispatch('e');
        $this->assertSame(0, $hit);
        $this->assertFalse($hook->removeAction('e', 'guarded'), 'Повторное удаление — false.');
    }

    public function testCountsAndRemoveAll(): void
    {
        $hook = new Hook();
        $hook->addAction('a', static function (): void {});
        $hook->addAction('b', static function (): void {});

        $this->assertSame(1, $hook->listenerCount('a'));
        $this->assertTrue($hook->has('a'));
        $this->assertFalse($hook->has('zzz'));

        $hook->removeAll('a');
        $this->assertFalse($hook->has('a'));
        $this->assertTrue($hook->has('b'));

        $hook->removeAll();
        $this->assertFalse($hook->has('b'));
    }
}
