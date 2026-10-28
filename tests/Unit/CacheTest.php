<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Module\Cache\CacheManager;
use App\Core\Module\Cache\Internal\Stores\ArrayStore;
use App\Core\Module\Cache\Internal\Stores\FileStore;
use Tests\Framework\TestCase;

/**
 * Тесты модуля Cache: TTL, remember, инкременты, атомарность file-стора.
 */
final class CacheTest extends TestCase
{
    private string $dir = '';

    public function __construct()
    {
        $this->dir = sys_get_temp_dir() . '/engine_cache_test_' . bin2hex(random_bytes(4));
    }

    public function testArrayDriverBasics(): void
    {
        $cache = new CacheManager(new ArrayStore(), 'array');

        $this->assertSame('array', $cache->driver());
        $this->assertTrue($cache->set('k', ['v' => 1]));
        $this->assertSame(['v' => 1], $cache->get('k'));
        $this->assertSame('def', $cache->get('missing', 'def'));
        $this->assertTrue($cache->has('k'));
        $this->assertTrue($cache->forget('k'));
        $this->assertFalse($cache->has('k'));
    }

    public function testTtlExpiryWithFakeClock(): void
    {
        $now   = 1000;
        $store = new FileStore($this->dir, static function () use (&$now): int { return $now; });
        $cache = new CacheManager($store, 'file');

        $cache->set('short', 'val', 10);
        $this->assertSame('val', $cache->get('short'));

        $now += 11; // время вышло
        $this->assertNull($cache->get('short'), 'Просроченный ключ отдаёт null и чистится.');
        $this->assertFalse($cache->has('short'));
    }

    public function testRememberComputesOnce(): void
    {
        $cache = new CacheManager(new ArrayStore(), 'array');
        $calls = 0;
        $fn = static function () use (&$calls): string {
            $calls++;
            return 'computed';
        };

        $this->assertSame('computed', $cache->remember('r', 60, $fn));
        $this->assertSame('computed', $cache->remember('r', 60, $fn));
        $this->assertSame(1, $calls, 'Колбэк вызывается ровно один раз.');
    }

    public function testIncDec(): void
    {
        $cache = new CacheManager(new ArrayStore(), 'array');

        // Инкремент несуществующего ключа — false (контракт стора).
        $this->assertFalse($cache->inc('counter'));

        $cache->set('counter', 0);
        $this->assertSame(1, $cache->inc('counter'));
        $this->assertSame(4, $cache->inc('counter', 3));
        $this->assertSame(2, $cache->dec('counter', 2));
    }

    public function testFileStoreAtomicRoundtripAndFlush(): void
    {
        $cache = new CacheManager(new FileStore($this->dir), 'file');

        $cache->set('a/b:c', ['data' => 'тест'], 0);
        $this->assertSame(['data' => 'тест'], $cache->get('a/b:c'), 'Спецсимволы в ключе не ломают путь.');

        $cache->set('x', 1);
        $this->assertTrue($cache->flush());
        $this->assertNull($cache->get('x'));

        $this->cleanup();
    }

    public function testUnknownDriverThrows(): void
    {
        $this->expectException(
            \RuntimeException::class,
            static fn (): mixed => CacheManager::fromConfig('memcached', '', [], null),
            'неизвестный драйвер'
        );
    }

    private function cleanup(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }
}
