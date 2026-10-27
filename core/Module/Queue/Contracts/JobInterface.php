<?php
declare(strict_types=1);

namespace App\Core\Module\Queue\Contracts;

/**
 * Контракт единицы работы в очереди. Бизнес-классы задач реализуют его,
 * очередь вызывает handle() в рабочем процессе (worker).
 */
interface JobInterface
{
    /** Выполнить задачу. */
    public function handle(): void;

    /** Имя задачи для логов/мониторинга. */
    public function name(): string;
}
