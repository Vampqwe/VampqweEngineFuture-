<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Module\Queue\Internal\Drivers\FileQueueDriver;
use App\Core\Module\Queue\QueueManager;
use Tests\Framework\TestCase;

/**
 * Регрессия CI-fatal: union-тип `array|true` в allowedJobClasses() парсился
 * как имя класса (зарезервированное слово) — загрузка FileQueueDriver падала.
 * Сам факт инстанцирования драйвера и roundtrip'а доказывает исправление.
 */
final class QueueWhitelistTest extends TestCase
{
    private string $dir = '';

    public function __construct()
    {
        $this->dir = sys_get_temp_dir() . '/engine_queue_wl_' . bin2hex(random_bytes(4));
    }

    public function testClassLoadsAndRoundtripSurvivesWhitelist(): void
    {
        $queue = new QueueManager(new FileQueueDriver($this->dir), 'file', 3, 60, 30);

        $id  = $queue->push(new WlEchoJob());
        $job = $queue->pop();

        // payload был десериализован через whitelist (allowed_classes = массив),
        // джоб восстановлен живым и корректно подтверждён.
        $this->assertNotNull($job, 'Драйвер обязан резолвить whitelist-классы.');
        $this->assertSame((string) $id, (string) $job->id(), 'ID из payload должен совпадать с id джоба.');
        $this->assertTrue($queue->ack((string) $job->id()));
        $this->assertNull($queue->pop(), 'Очередь пуста после ack.');

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

/**
 * Легальный джоб: реализация JobInterface попадает в whitelist автоматически.
 * ВАЖНО: поля приватные — serialize() сохраняет их имена с префиксом длины
 * ("\0Класс\0поле"), и unserialize может восстановить объект только того же
 * класса, из которого была сериализация (тот же FQN). Наследник QueueJob с
 * другими private-полями при восстановлении создал бы отдельный экземпляр
 * с queueId=null — поэтому тестовый джоб наследует базовый QueueJob, как
 * боевые задачи приложения.
 */
final class WlEchoJob extends \App\Core\Module\Queue\Internal\QueueJob
{
    public function handle(): void {}
    public function name(): string { return 'wl-echo'; }
}
