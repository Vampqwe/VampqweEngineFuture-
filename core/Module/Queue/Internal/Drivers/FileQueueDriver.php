<?php
declare(strict_types=1);

namespace App\Core\Module\Queue\Internal\Drivers;

use App\Core\Module\Queue\Contracts\JobInterface;

/**
 * Внутренний файловый бэкенд очереди. ЗАПРЕЩЁН извне модуля Queue (п.2).
 *
 * Layout (каталог = Paths::MAP['queue']):
 *   pending/<id>.json  — готовые к выполнению задачи
 *   delayed/<id>.json  — задачи с avail_at в будущем
 *   reserved/<id>.json — зарезервированные воркером (видимость истекла -> возврат)
 *   failed/<id>.json   — проваленные навсегда (с текстом ошибки)
 *
 * Конкурентность: pop между процессами обеспечивается атомарным rename()
 * (только один воркер добирается до файла). serialize() всего джоба — осознанный
 * выбор вместо json: задачи — PHP-классы движка, это внутренний формат.
 */
final class FileQueueDriver implements DriverInterface
{
    public function __construct(private readonly string $directory)
    {
        foreach (['pending', 'delayed', 'reserved', 'failed'] as $d) {
            if (!is_dir($this->directory . '/' . $d) && !@mkdir($this->directory . '/' . $d, 0775, true) && !is_dir($this->directory . '/' . $d)) {
                throw new \RuntimeException("Queue[file]: нельзя создать каталог {$this->directory}/{$d}");
            }
        }
    }

    public function push(JobInterface $job, int $delaySeconds): string
    {
        $id = bin2hex(random_bytes(8));
        $job->setId($id);
        $payload = [
            'id'       => $id,
            'attempts' => 0,
            'avail_at' => time() + max(0, $delaySeconds),
            'job'      => serialize($job),
        ];
        $dir = $delaySeconds > 0 ? 'delayed' : 'pending';
        return $this->atomicWrite($this->directory . '/' . $dir . '/' . $id . '.json', $payload)
            ? $id : throw new \RuntimeException('Queue[file]: не удалось записать задачу.');
    }

    public function reserve(int $visibilitySeconds): ?array
    {
        $now = time();

        // 1) просроченные резервации возвращаем в pending (воркер умер)
        foreach ($this->files('reserved') as $f) {
            $p = $this->readJson($f);
            if ($p !== null && ($p['visible_until'] ?? 0) < $now) {
                @rename($f, $this->directory . '/pending/' . basename($f));
            }
        }

        // 2) пришедшие delayed переводим в pending
        foreach ($this->files('delayed') as $f) {
            $p = $this->readJson($f);
            if ($p !== null && ($p['avail_at'] ?? 0) <= $now) {
                @rename($f, $this->directory . '/pending/' . basename($f));
            }
        }

        // 3) берём первую готовую: rename атомарен — гонки нет
        $candidates = glob($this->directory . '/pending/*.json') ?: [];
        sort($candidates); // FIFO по имени (mtime-порядок fs может нарушать)
        foreach ($candidates as $f) {
            $id = basename($f, '.json');
            $target = $this->directory . '/reserved/' . $id . '.json';
            if (!@rename($f, $target)) {
                continue; // успел другой воркер
            }
            $p = $this->readJson($target);
            if ($p === null || !isset($p['job'])) {
                @unlink($target);
                continue;
            }
            $p['attempts'] = (int) ($p['attempts'] ?? 0) + 1;
            $p['visible_until'] = $now + max(5, $visibilitySeconds);
            $this->atomicWrite($target, $p);
            try {
                /** @var JobInterface $job */
                $job = unserialize((string) $p['job'], ['allowed_classes' => true]);
            } catch (\Throwable) {
                // повреждённый payload — не должен блокировать очередь
                $this->toFailedRaw($target, 'unserialize failed');
                continue;
            }
            if (!$job instanceof JobInterface) {
                $this->toFailedRaw($target, 'payload is not a JobInterface');
                continue;
            }
            $job->setId($id);
            for ($i = 0; $i < $p['attempts']; $i++) {
                $job->addAttempt();
            }
            return [$job, $id];
        }

        return null;
    }

    /** Повреждённый reserved-файл -> failed/ с пояснением. */
    private function toFailedRaw(string $reservedFile, string $reason): void
    {
        $dst = $this->directory . '/failed/' . basename($reservedFile);
        @rename($reservedFile, $dst);
        $p = $this->readJson($dst);
        if ($p !== null) {
            $p['error'] = $reason;
            $p['failed_at'] = time();
            $this->atomicWrite($dst, $p);
        }
    }

    public function ack(string $payloadId): bool
    {
        $f = $this->directory . '/reserved/' . $payloadId . '.json';
        return !is_file($f) || @unlink($f);
    }

    public function release(string $payloadId, int $delaySeconds): bool
    {
        $src = $this->directory . '/reserved/' . $payloadId . '.json';
        $p = $this->readJson($src);
        if ($p === null) {
            return false;
        }
        unset($p['visible_until']);
        $p['avail_at'] = time() + max(0, $delaySeconds);
        $dstDir = $delaySeconds > 0 ? 'delayed' : 'pending';
        $dst = $this->directory . '/' . $dstDir . '/' . $payloadId . '.json';
        @unlink($src);
        return $this->atomicWrite($dst, $p);
    }

    public function toFailed(string $payloadId, string $error): bool
    {
        $src = $this->directory . '/reserved/' . $payloadId . '.json';
        $p = $this->readJson($src);
        if ($p === null) {
            return false;
        }
        $p['failed_at'] = time();
        $p['error'] = $error;
        unset($p['visible_until']);
        @unlink($src);
        return $this->atomicWrite($this->directory . '/failed/' . $payloadId . '.json', $p);
    }

    public function size(): int
    {
        return count($this->files('pending')) + count($this->files('delayed'));
    }

    public function isAvailable(): bool
    {
        return is_dir($this->directory . '/pending') && is_writable($this->directory . '/pending');
    }

    /** @return list<string> */
    private function files(string $sub): array
    {
        return glob($this->directory . '/' . $sub . '/*.json') ?: [];
    }

    /** @return array<string,mixed>|null */
    private function readJson(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if (!is_string($raw)) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private function atomicWrite(string $file, array $payload): bool
    {
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, json_encode($payload, JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
            return false;
        }
        if (!rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }
}
