<?php
declare(strict_types=1);

namespace App\Core\Module\Queue\Internal;

use App\Core\Module\Queue\Contracts\JobInterface;

/**
 * Базовый класс задач. Наследуются бизнес-джобы приложения (app/modules),
 * сериализуются драйверами очереди целиком (public-поля включены).
 * ВАЖНО: handler не должен хранить в $this тяжёлые/несериализуемые ресурсы.
 */
abstract class QueueJob implements JobInterface
{
    private ?string $queueId = null;
    private int $attempts = 0;

    abstract public function handle(): void;

    public function name(): string
    {
        return static::class;
    }

    public function setId(string $id): void
    {
        $this->queueId = $id;
    }

    public function id(): ?string
    {
        return $this->queueId;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function addAttempt(): int
    {
        return ++$this->attempts;
    }

    public function fail(\Throwable $error): void
    {
        // Переопределяется джобами: уведомления, откат бизнес-состояний.
    }
}
