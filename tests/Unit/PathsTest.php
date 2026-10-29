<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\System\Support\Paths;
use Tests\Framework\TestCase;

/**
 * Тесты единой точки правды о путях (п.7 Конституции).
 */
final class PathsTest extends TestCase
{
    public function testGetJoinsBaseAndRelative(): void
    {
        $paths = new Paths('/var/www/engine');

        $this->assertSame('/var/www/engine/logs', $paths->get('logs'));
        $this->assertSame('/var/www/engine/storage/cache', $paths->get('cache'));
        $this->assertSame('/var/www/engine/view/app', $paths->get('app_view'));
        // Контракт: base = base_path + '/' + '.' — нормализуется той же операцией,
        // что и в App; проверяем через realpath-эквивалент строки.
        $this->assertSame('/var/www/engine/.', $paths->get('base'));
    }

    public function testUnknownKeyThrows(): void
    {
        $paths = new Paths('/tmp');
        $this->expectException(
            \InvalidArgumentException::class,
            static fn (): mixed => $paths->get('no_such_key'),
            'не объявлен'
        );
    }

    public function testAllCoversEveryKey(): void
    {
        $paths = new Paths('/p');
        $all   = $paths->all();

        foreach (Paths::keys() as $key) {
            $this->assertNotNull($all[$key] ?? null, "Ключ [{$key}] обязан присутствовать в all().");
            $this->assertTrue(str_starts_with($all[$key], '/p/'), 'Все пути абсолютны от base.');
        }
    }

    public function testBasePathAccessor(): void
    {
        $this->assertSame('/engine-root', (new Paths('/engine-root'))->basePath());
    }
}
