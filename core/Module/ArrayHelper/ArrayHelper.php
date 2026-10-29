<?php
declare(strict_types=1);

namespace App\Core\Module\ArrayHelper;

use App\Core\Module\ArrayHelper\Contracts\ArrayHelperInterface;

/**
 * Работа с массивами: точечная нотация, рекурсивное слияние, выборки.
 * Модуль нулевого уровня — зависимостей не имеет (п.6 Конституции).
 */
final class ArrayHelper implements ArrayHelperInterface
{
    public function get(array $array, string $key, mixed $default = null): mixed
    {
        if ($key === '') {
            return $default;
        }
        if (array_key_exists($key, $array)) {
            return $array[$key];
        }

        $current = $array;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    public function has(array $array, string $key): bool
    {
        if (array_key_exists($key, $array)) {
            return true;
        }

        $current = $array;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return false;
            }
            $current = $current[$segment];
        }

        return true;
    }

    public function set(array $array, string $key, mixed $value): array
    {
        $segments = explode('.', $key);
        $ref = &$array;
        foreach ($segments as $segment) {
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }
        $ref = $value;

        return $array;
    }

    public function forget(array $array, string $key): array
    {
        $segments = explode('.', $key);
        $last = array_pop($segments);
        $ref = &$array;
        foreach ($segments as $segment) {
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                return $array;
            }
            $ref = &$ref[$segment];
        }
        unset($ref[$last]);

        return $array;
    }

    public function mergeDeep(array $left, array $right): array
    {
        foreach ($right as $key => $value) {
            if (is_array($value) && isset($left[$key]) && is_array($left[$key])) {
                $left[$key] = $this->mergeDeep($left[$key], $value);
            } else {
                $left[$key] = $value;
            }
        }

        return $left;
    }

    public function undot(array $array): array
    {
        $out = [];
        foreach ($array as $key => $value) {
            $out = $this->set($out, (string) $key, $value);
        }

        return $out;
    }

    public function only(array $array, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $array)) {
                $out[$key] = $array[$key];
            }
        }

        return $out;
    }

    public function except(array $array, array $keys): array
    {
        return array_diff_key($array, array_flip($keys));
    }

    public function first(array $array, ?callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            return $array === [] ? $default : reset($array);
        }
        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                return $value;
            }
        }

        return $default;
    }

    public function filter(array $array, callable $callback): array
    {
        return array_filter($array, $callback, ARRAY_FILTER_USE_BOTH);
    }

    public function mapKeysRecursive(array $array, callable $callback): array
    {
        $out = [];
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $value = $this->mapKeysRecursive($value, $callback);
            }
            $out[(string) $callback((string) $key, $value)] = $value;
        }

        return $out;
    }
}
