<?php
declare(strict_types=1);

namespace App\Core\Module\ArrayHelper\Contracts;

/**
 * Контракт работы с массивами. Единственная публичная граница модуля ArrayHelper.
 * Поддержка точечной нотации: 'app.debug', 'users.0.name'.
 */
interface ArrayHelperInterface
{
    /** Значение по точечному ключу, если нет — $default. */
    public function get(array $array, string $key, mixed $default): mixed;

    /** Существование ключа по точечной нотации. */
    public function has(array $array, string $key): bool;

    /** Запись значения по точечному ключу (создаёт промежуточные уровни). Возвращает новый массив. */
    public function set(array $array, string $key, mixed $value): array;

    /** Удаление ключа по точечной нотации. Возвращает новый массив. */
    public function forget(array $array, string $key): array;

    /** Рекурсивное слияние: right перекрывает left. */
    public function mergeDeep(array $left, array $right): array;

    /** Плоский массив ['a.b' => value] => древовидный. */
    public function undot(array $array): array;

    /** Только указанные ключи. */
    public function only(array $array, array $keys): array;

    /** Всё, кроме указанных ключей. */
    public function except(array $array, array $keys): array;

    /** Первый элемент, проходящий через callback. */
    public function first(array $array, ?callable $callback, mixed $default): mixed;

    /** Все элементы, проходящие через callback (значение + ключ). */
    public function filter(array $array, callable $callback): array;

    /** Рекурсивное преобразование ключей. */
    public function mapKeysRecursive(array $array, callable $callback): array;
}
