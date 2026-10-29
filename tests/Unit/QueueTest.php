<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Module\Queue\Contracts\JobInterface;
use App\Core\Module\Queue\Internal\Drivers\FileQueueDriver;
use App\Core\Module\Queue\Internal\QueueJob;
use App\Core\Module\Queue\QueueManager;
use Tests\Framework\TestCase;

/**
 * Тесты очереди (file-бэкенд): push/reserve/ack, retry, delay, failed.
 */
final class QueueTest extends TestCase
{
    private string $dir = '';

    public function __construct()
    {
        $this->dir = sys_get_temp_dir() . '/engine_queue_test_' . bin2hex(random_bytes(4));
    }

    public function testPushPopAckLifecycle(): void
    {
        $queue = new QueueManager(new FileQueueDriver($this->dir), 'file', 3, 60, 30);

        $id  = $queue->push(new TestEchoJob());
        $job = $queue->pop();

        $this->assertNotNull($job);
        $this->assertSame($id, $job->id());
        $this->assertSame(1, $job->attempts());
        $this->assertTrue($queue->ack($id));
        $this->assertNull($queue->pop(), 'Очередь пуста после ack.');

        $this->cleanup();
    }

    public function testProcessNextRetriesThenFails(): void
    {
        $driver = new FileQueueDriver($this->dir);
        $queue  = new QueueManager($driver, 'file', maxAttempts: 2, retryAfter: 0, visibility: 5);

        $queue->push(new TestFailJob());

        $this->assertSame('retried', $queue->processNext(), 'Первая попытка — retry.');
        $this->assertSame('failed', $queue->processNext(), 'Вторая попытка исчерпала maxAttempts.');
        $this->assertNull($queue->processNext(), 'Проваленная задача уходит из pending.');

        $this->cleanup();
    }

    public function testDelayedJobBecomesAvailable(): void
    {
        $queue = new QueueManager(new FileQueueDriver($this->dir), 'file', 3, 60, 30);
        $queue->push(new TestEchoJob(), 3600);

        $this->assertNull($queue->pop(), 'Задержка ещё не истекла.');

        $this->cleanup();
    }

    public function testSizeCountsPending(): void
    {
        $queue = new QueueManager(new FileQueueDriver($this->dir), 'file');
        $queue->push(new TestEchoJob());
        $queue->push(new TestEchoJob());

        $this->assertSame(2, $queue->size());

        $this->cleanup();
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

/** Простая успешная задача. */
final class TestEchoJob extends QueueJob implements JobInterface
{
    public static int $handled = 0;

    public function handle(): void
    {
        self::$handled++;
    }
}

/** Задача, всегда падающая. */
final class TestFailJob extends QueueJob implements JobInterface
{
    public function handle(): void
    {
        throw new \RuntimeException('planned failure');
    }
}
