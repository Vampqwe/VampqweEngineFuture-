<?php
declare(strict_types=1);

namespace App\Core\Module\Hook;

use App\Core\Module\Hook\Contracts\HookInterface;
use App\Core\Module\Hook\Internal\ListenerRegistry;

/**
 * Модуль Hook — событийно-фильтровая шина движка (фасад).
 *
 * Зависимости: нет (уровень 0) — чистый in-process реестр.
 * Опционально логгирует сбои слушателей через Logger, если он уже в контейнере
 * (регистрируется позже Logger'а не будет — порядок манифеста это гарантирует).
 *
 * Внутренний мир (ListenerRegistry) наружу не отдаётся: публичная граница —
 * только HookInterface (п.2 Конституции).
 */
final class Hook implements HookInterface
{
    private ListenerRegistry $registry;

    /** @var callable|null fn(string $message, array $context): void — мост к Logger. */
    private $errorSink = null;

    public function __construct()
    {
        $this->registry = new ListenerRegistry();
    }

    /**
     * Инъекция приёмника ошибок (вызывается провайдером после boot Logger'а).
     * Нужна, чтобы исключение в чужом слушателе не молча терялось.
     */
    public function setErrorSink(?callable $sink): void
    {
        $this->errorSink = $sink;
    }

    public function addAction(string $hook, callable $listener, int $priority = 10, ?string $id = null): void
    {
        $this->registry->add($hook, $listener, $priority, $id);
    }

    public function removeAction(string $hook, string $id): bool
    {
        return $this->registry->remove($hook, $id);
    }

    public function applyFilters(string $hook, mixed $value, mixed ...$args): mixed
    {
        foreach ($this->registry->sortedListeners($hook) as $listener) {
            try {
                $result = $listener($value, ...$args);
                // Слушатель-фильтр обязан вернуть значение; void/null считаем
                // «значением нет» только если фильтр явно вернул null-цепочку.
                $value = $result;
            } catch (\Throwable $e) {
                $this->report($hook, $e);
                // цепочку не ломаем: значение остаётся прежним
            }
        }

        return $value;
    }

    public function dispatch(string $hook, mixed ...$args): void
    {
        foreach ($this->registry->sortedListeners($hook) as $listener) {
            try {
                $listener(...$args);
            } catch (\Throwable $e) {
                $this->report($hook, $e);
            }
        }
    }

    public function has(string $hook): bool
    {
        return $this->registry->count($hook) > 0;
    }

    public function listenerCount(string $hook): int
    {
        return $this->registry->count($hook);
    }

    public function removeAll(?string $hook = null): void
    {
        $this->registry->removeAll($hook);
    }

    private function report(string $hook, \Throwable $e): void
    {
        if ($this->errorSink !== null) {
            ($this->errorSink)(
                "Слушатель хука [{$hook}] упал: {$e->getMessage()}",
                ['exception' => $e, 'channel' => 'Hook', 'hook' => $hook],
            );
        }
        // При отсутствии Logger тишина допустима: движок не должен падать
        // из-за чужой подписки — это осознанное решение фильтра.
    }
}
