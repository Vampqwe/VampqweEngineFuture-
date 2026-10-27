<?php
declare(strict_types=1);

namespace App\Core\Module\Hook\Contracts;

/**
 * Контракт системы хуков (событийно-фильтровая модель).
 * Единственная публичная граница модуля Hook — другие модули и бизнес-логика
 * общаются с событиями ТОЛЬКО через этот интерфейс (п.2 Конституции).
 *
 * Две категории точек расширения:
 *  - ФИЛЬТРЫ (имеют значение): applyFilters() прогоняет значение через цепочку
 *    слушателей, каждый может изменить его и вернуть дальше. Имена в стиле
 *    "engine.page.content", "engine.response".
 *  - ДЕЙСТВИЯ (без значения): dispatch() просто уведомляет подписчиков.
 *    Имена в стиле "engine.booted", "installer.finished".
 */
interface HookInterface
{
    /**
     * Подписаться на хук.
     *
     * @param string      $hook     Имя хука (точечная нотация приветствуется).
     * @param callable    $listener Функция-слушатель. Для фильтров сигнатура:
     *                              fn(mixed $value, mixed ...$args): mixed
     *                              для действий: fn(mixed ...$args): void
     * @param int         $priority Меньше = раньше. Дефолт 10. Коллеги на одном
     *                              приоритете выполняются в порядке регистрации.
     * @param string|null $id       Необязательный идентификатор подписки
     *                              (для последующего снятия removeAction()).
     */
    public function addAction(string $hook, callable $listener, int $priority = 10, ?string $id = null): void;

    /** Снять подписку по имени хука и идентификатору. Возвращает true, если снята. */
    public function removeAction(string $hook, string $id): bool;

    /**
     * Прогнать значение через все слушатели хука (фильтр).
     * Каждый слушатель получает текущее значение + доп. аргументы и возвращает новое.
     * Исключение в слушателе логируется и НЕ ломает цепочку (значение сохраняется).
     */
    public function applyFilters(string $hook, mixed $value, mixed ...$args): mixed;

    /** Уведомить всех слушателей хука (действие). Значения не возвращает. */
    public function dispatch(string $hook, mixed ...$args): void;

    /** Есть ли хотя бы одна живая подписка на хук. */
    public function has(string $hook): bool;

    /** Количество подписок на хук (0, если хук неизвестен). */
    public function listenerCount(string $hook): int;

    /**
     * Снять ВСЕ подписки на хук (или все вообще, если $hook === null).
     * Полезно модулям при выключении/перезагрузке.
     */
    public function removeAll(?string $hook = null): void;
}
