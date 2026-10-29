<?php
declare(strict_types=1);

namespace App\Core\Module\Hook\Internal;

/**
 * ВНУТРЕННИЙ КЛАСС модуля Hook — реестр слушателей.
 * Запрещён к использованию из других модулей (п.2 Конституции, Internal/).
 *
 * Хранит подписки: hook => [priority => [seq => ['id'=>?string,'fn'=>callable]]].
 * Сортировка по приоритету выполняется лениво и кешируется до следующей
 * мутации реестра (stable sort по seq сохраняет порядок регистрации).
 */
final class ListenerRegistry
{
    /** @var array<string, array<int, array<int, array{id: ?string, fn: callable}>>> */
    private array $listeners = [];

    /** Глобальный счётчик порядка регистрации (для стабильной сортировки). */
    private int $sequence = 0;

    /** @var array<string, list<callable>> кеш отсортированных цепочек по хуку */
    private array $sortedCache = [];

    public function add(string $hook, callable $listener, int $priority, ?string $id): void
    {
        $this->listeners[$hook][$priority][$this->sequence++] = [
            'id' => $id,
            'fn' => $listener,
        ];
        unset($this->sortedCache[$hook]);
    }

    public function remove(string $hook, string $id): bool
    {
        if (!isset($this->listeners[$hook])) {
            return false;
        }

        foreach ($this->listeners[$hook] as $priority => $bucket) {
            foreach ($bucket as $seq => $entry) {
                if ($entry['id'] === $id) {
                    unset($this->listeners[$hook][$priority][$seq]);
                    if ($this->listeners[$hook][$priority] === []) {
                        unset($this->listeners[$hook][$priority]);
                    }
                    if ($this->listeners[$hook] === []) {
                        unset($this->listeners[$hook]);
                    }
                    unset($this->sortedCache[$hook]);
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Отсортированная цепочка слушателей хука (по возрастанию приоритета,
     * внутри приоритета — по порядку регистрации).
     *
     * @return list<callable>
     */
    public function sortedListeners(string $hook): array
    {
        if (isset($this->sortedCache[$hook])) {
            return $this->sortedCache[$hook];
        }

        $buckets = $this->listeners[$hook] ?? [];
        ksort($buckets); // priority: меньше = раньше

        $chain = [];
        foreach ($buckets as $bucket) {
            // seq уже уникален и возрастает при регистрации — порядок стабилен
            foreach ($bucket as $entry) {
                $chain[] = $entry['fn'];
            }
        }

        return $this->sortedCache[$hook] = $chain;
    }

    public function count(string $hook): int
    {
        return count($this->sortedListeners($hook));
    }

    public function removeAll(?string $hook = null): void
    {
        if ($hook === null) {
            $this->listeners   = [];
            $this->sortedCache = [];
            return;
        }
        unset($this->listeners[$hook], $this->sortedCache[$hook]);
    }
}
