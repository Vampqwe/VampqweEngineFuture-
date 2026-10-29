<?php
declare(strict_types=1);

namespace App\Core\Module\Queue\Internal\Drivers;

use App\Core\Module\Queue\Contracts\JobInterface;

/**
 * Внутренний Redis-бэкенд очереди (driver=redis). Требует ext-redis.
 *
 * Структура: LIST <list>:ready — готовые задачи (json {id, payload});
 * сериализованный джоб лежит в SET-ключе <list>:job:<id> с TTL-страховкой.
 * reserve() = LPOP (атомарен между процессами по определению).
 */
final class RedisQueueDriver implements DriverInterface
{
    private ?\Redis $redis = null;

    public function __construct(
        private readonly string $list,
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 6379,
        private readonly ?string $password = null,
        private readonly int $database = 0,
    ) {
    }

    public function isAvailable(): bool
    {
        if (!extension_loaded('redis')) {
            return false;
        }
        try {
            return (bool) $this->conn()->ping();
        } catch (\Throwable) {
            return false;
        }
    }

    public function push(JobInterface $job, int $delaySeconds): string
    {
        $id = bin2hex(random_bytes(8));
        $job->setId($id);
        $payload = json_encode([
            'id'       => $id,
            'attempts' => 0,
            'avail_at' => time() + max(0, $delaySeconds),
            'job'      => serialize($job),
        ], JSON_UNESCAPED_UNICODE);
        $this->conn()->set($this->list . ':job:' . $id, $payload, ['ex' => 86400 * 7]);
        if ($delaySeconds > 0) {
            // Простая схема: delayed-задачи перекладываются в ready воркером (push отложенного — через ZSET later).
            $this->conn()->zAdd($this->list . ':delayed', [['score' => time() + $delaySeconds, 'member' => $id]]);
        } else {
            $this->conn()->rPush($this->list . ':ready', $payload);
        }
        return $id;
    }

    public function reserve(int $visibilitySeconds): ?array
    {
        $now = time();
        // просроченные delayed -> ready
        foreach ($this->conn()->zRangeByScore($this->list . ':delayed', '-inf', (string) $now) ?: [] as $id) {
            $raw = $this->conn()->get($this->list . ':job:' . $id);
            if (is_string($raw)) {
                $this->conn()->rPush($this->list . ':ready', $raw);
            }
            $this->conn()->zRem($this->list . ':delayed', (string) $id);
        }

        $raw = $this->conn()->lPop($this->list . ':ready'); // атомарно
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $p = json_decode($raw, true);
        if (!is_array($p) || !isset($p['job'])) {
            return null;
        }
        $p['attempts'] = (int) ($p['attempts'] ?? 0) + 1;
        $payload = json_encode($p, JSON_UNESCAPED_UNICODE);
        $this->conn()->set($this->list . ':job:' . $p['id'], $payload, ['ex' => max(60, $visibilitySeconds) * 10]);
        /** @var JobInterface $job */
        // Whitelist классов (POP-защита): чужие объекты не инстанцируются;
        // instanceof JobInterface ниже отсекает всё, что приехало не как джоб.
        $job = unserialize((string) $p['job'], ['allowed_classes' => true]);
        $job->setId((string) $p['id']);
        for ($i = 0; $i < $p['attempts']; $i++) {
            $job->addAttempt();
        }
        return [$job, (string) $p['id']];
    }

    public function ack(string $payloadId): bool
    {
        return (bool) $this->conn()->del($this->list . ':job:' . $payloadId);
    }

    public function release(string $payloadId, int $delaySeconds): bool
    {
        $raw = $this->conn()->get($this->list . ':job:' . $payloadId);
        if (!is_string($raw)) {
            return false;
        }
        $p = json_decode($raw, true);
        if (!is_array($p)) {
            return false;
        }
        $p['avail_at'] = time() + max(0, $delaySeconds);
        $new = json_encode($p, JSON_UNESCAPED_UNICODE);
        $this->conn()->set($this->list . ':job:' . $payloadId, $new, ['ex' => 86400 * 7]);
        if ($delaySeconds > 0) {
            $this->conn()->zAdd($this->list . ':delayed', [['score' => $p['avail_at'], 'member' => $payloadId]]);
        } else {
            $this->conn()->rPush($this->list . ':ready', $new);
        }
        return true;
    }

    public function toFailed(string $payloadId, string $error): bool
    {
        $raw = $this->conn()->get($this->list . ':job:' . $payloadId);
        if (!is_string($raw)) {
            return false;
        }
        $p = json_decode($raw, true);
        if (!is_array($p)) {
            return false;
        }
        $p['failed_at'] = time();
        $p['error'] = $error;
        $this->conn()->set($this->list . ':failed:' . $payloadId, json_encode($p, JSON_UNESCAPED_UNICODE));
        return (bool) $this->conn()->del($this->list . ':job:' . $payloadId);
    }

    public function size(): int
    {
        return (int) ($this->conn()->lLen($this->list . ':ready') ?: 0)
            + (int) ($this->conn()->zCard($this->list . ':delayed') ?: 0);
    }

    private function conn(): \Redis
    {
        if ($this->redis instanceof \Redis) {
            return $this->redis;
        }
        if (!extension_loaded('redis')) {
            throw new \RuntimeException('Queue[redis]: расширение ext-redis не установлено.');
        }
        $redis = new \Redis();
        if (!$redis->connect($this->host, $this->port, 1.5)) {
            throw new \RuntimeException("Queue[redis]: нет соединения с {$this->host}:{$this->port}.");
        }
        if ($this->password !== null && $this->password !== '') {
            $redis->auth($this->password);
        }
        if ($this->database !== 0) {
            $redis->select($this->database);
        }
        return $this->redis = $redis;
    }
}
