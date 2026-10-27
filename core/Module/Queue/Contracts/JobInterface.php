<?php
declare(strict_types=1);

namespace App\Core\Module\Queue\Contracts;

/**
 * Контракт единицы работы в очереди. Бизнес-классы задач реализуют его,
 * worker вызывает handle() в фоновом процессе.
 */
interface JobInterface
{
    /** Выполнить задачу. Бросок исключения = неудача (учёт retry). */
    public function handle(): void;

    /** Имя задачи для логов/мониторинга. */
    public function name(): string;

    /** Идентификатор задачи в очереди (присваивается драйвером при push). */
    public function setId(string $id): void;

    public function id(): ?string;

    /** Счётчик попыток выполнения (ведёт worker). */
    public function attempts(): int;

    public function addAttempt(): int;

    /** Вызывается worker'ом после исчерпания max_attempts (задача уходит в failed). */
    public function fail(\Throwable $error): void;
}
