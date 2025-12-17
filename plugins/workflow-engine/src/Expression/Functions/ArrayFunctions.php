<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Expression\Functions;

/**
 * Array Functions Provider | 数组函数提供器
 *
 * Provides array manipulation functions for expressions.
 * 为表达式提供数组操作函数。
 *
 * Available functions:
 * - count(array) - Count elements
 * - first(array) - Get first element
 * - last(array) - Get last element
 * - keys(array) - Get array keys
 * - values(array) - Get array values
 * - merge(...arrays) - Merge arrays
 * - unique(array) - Remove duplicates
 * - reverse(array) - Reverse array
 * - sort(array) - Sort array
 * - filter(array, callback?) - Filter array
 * - map(array, callback) - Map array
 * - reduce(array, callback, initial?) - Reduce array
 * - find(array, callback) - Find first match
 * - contains(array, value) - Check if contains value
 * - indexOf(array, value) - Find index of value
 * - slice(array, start, length?) - Get slice
 * - chunk(array, size) - Split into chunks
 * - flatten(array, depth?) - Flatten nested array
 * - pluck(array, key) - Extract column
 * - groupBy(array, key) - Group by key
 * - keyBy(array, key) - Key by field
 * - sum(array, key?) - Sum values
 * - avg(array, key?) - Average values
 * - min(array, key?) - Minimum value
 * - max(array, key?) - Maximum value
 *
 * @package Alkaid\Workflow\Engine\Expression\Functions
 */
class ArrayFunctions extends AbstractFunctionProvider
{
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->registerArrayFunctions();
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'array';
    }

    /**
     * {@inheritdoc}
     */
    public function getDescription(): string
    {
        return '数组操作函数';
    }

    /**
     * Register array functions | 注册数组函数
     *
     * @return void
     */
    private function registerArrayFunctions(): void
    {
        // count(array) - Count elements
        $this->registerFunction('count', function (array $array): int {
            return count($array);
        });

        // first(array, default?) - Get first element
        $this->registerFunction('first', function (array $array, mixed $default = null): mixed {
            return empty($array) ? $default : reset($array);
        });

        // last(array, default?) - Get last element
        $this->registerFunction('last', function (array $array, mixed $default = null): mixed {
            return empty($array) ? $default : end($array);
        });

        // keys(array) - Get array keys
        $this->registerFunction('keys', function (array $array): array {
            return array_keys($array);
        });

        // values(array) - Get array values
        $this->registerFunction('values', function (array $array): array {
            return array_values($array);
        });

        // merge(...arrays) - Merge arrays
        $this->registerFunction('merge', function (...$arrays): array {
            return array_merge(...$arrays);
        });

        // unique(array) - Remove duplicates
        $this->registerFunction('unique', function (array $array): array {
            return array_values(array_unique($array));
        });

        // reverse(array) - Reverse array
        $this->registerFunction('arrayReverse', function (array $array): array {
            return array_reverse($array);
        });

        // sort(array, direction?) - Sort array
        $this->registerFunction('arraySort', function (array $array, string $direction = 'asc'): array {
            if ($direction === 'desc') {
                rsort($array);
            } else {
                sort($array);
            }
            return $array;
        });

        // contains(array, value) - Check if contains value
        $this->registerFunction('inArray', function (array $array, mixed $value): bool {
            return in_array($value, $array, true);
        });

        // indexOf(array, value) - Find index of value
        $this->registerFunction('indexOf', function (array $array, mixed $value): int|false {
            return array_search($value, $array, true);
        });

        // slice(array, start, length?) - Get slice
        $this->registerFunction('slice', function (array $array, int $start, ?int $length = null): array {
            return array_slice($array, $start, $length);
        });

        // chunk(array, size) - Split into chunks
        $this->registerFunction('chunk', function (array $array, int $size): array {
            return array_chunk($array, max(1, $size));
        });

        // flatten(array, depth?) - Flatten nested array
        $this->registerFunction('flatten', function (array $array, int $depth = 1): array {
            return $this->flattenArray($array, $depth);
        });

        // pluck(array, key) - Extract column
        $this->registerFunction('pluck', function (array $array, string $key): array {
            return array_column($array, $key);
        });

        // groupBy(array, key) - Group by key
        $this->registerFunction('groupBy', function (array $array, string $key): array {
            $result = [];
            foreach ($array as $item) {
                if (is_array($item) && isset($item[$key])) {
                    $result[$item[$key]][] = $item;
                }
            }
            return $result;
        });

        // keyBy(array, key) - Key by field
        $this->registerFunction('keyBy', function (array $array, string $key): array {
            $result = [];
            foreach ($array as $item) {
                if (is_array($item) && isset($item[$key])) {
                    $result[$item[$key]] = $item;
                }
            }
            return $result;
        });

        // sumBy(array, key?) - Sum values by key
        $this->registerFunction('sumBy', function (array $array, ?string $key = null): int|float {
            if ($key === null) {
                return array_sum($array);
            }
            return array_sum(array_column($array, $key));
        });

        // avgBy(array, key?) - Average values by key
        $this->registerFunction('avgBy', function (array $array, ?string $key = null): float {
            if (empty($array)) {
                return 0.0;
            }
            $values = $key === null ? $array : array_column($array, $key);
            return count($values) > 0 ? array_sum($values) / count($values) : 0.0;
        });

        // minBy(array, key?) - Minimum value by key
        $this->registerFunction('minBy', function (array $array, ?string $key = null): mixed {
            if (empty($array)) {
                return null;
            }
            $values = $key === null ? $array : array_column($array, $key);
            return empty($values) ? null : min($values);
        });

        // maxBy(array, key?) - Maximum value by key
        $this->registerFunction('maxBy', function (array $array, ?string $key = null): mixed {
            if (empty($array)) {
                return null;
            }
            $values = $key === null ? $array : array_column($array, $key);
            return empty($values) ? null : max($values);
        });

        // push(array, ...values) - Push values to array
        $this->registerFunction('push', function (array $array, ...$values): array {
            foreach ($values as $value) {
                $array[] = $value;
            }
            return $array;
        });

        // pop(array) - Pop last element
        $this->registerFunction('pop', function (array $array): array {
            array_pop($array);
            return $array;
        });

        // shift(array) - Remove first element
        $this->registerFunction('shift', function (array $array): array {
            array_shift($array);
            return $array;
        });

        // unshift(array, ...values) - Add to beginning
        $this->registerFunction('unshift', function (array $array, ...$values): array {
            array_unshift($array, ...$values);
            return $array;
        });

        // get(array, key, default?) - Get value by key
        $this->registerFunction('get', function (array $array, string|int $key, mixed $default = null): mixed {
            return $array[$key] ?? $default;
        });

        // set(array, key, value) - Set value by key
        $this->registerFunction('set', function (array $array, string|int $key, mixed $value): array {
            $array[$key] = $value;
            return $array;
        });

        // has(array, key) - Check if key exists
        $this->registerFunction('has', function (array $array, string|int $key): bool {
            return array_key_exists($key, $array);
        });

        // remove(array, key) - Remove by key
        $this->registerFunction('remove', function (array $array, string|int $key): array {
            unset($array[$key]);
            return array_values($array);
        });

        // where(array, key, operator, value) - Filter by condition
        $this->registerFunction('where', function (array $array, string $key, string $operator, mixed $value): array {
            return array_values(array_filter($array, function ($item) use ($key, $operator, $value) {
                if (!is_array($item) || !isset($item[$key])) {
                    return false;
                }
                $itemValue = $item[$key];
                return match ($operator) {
                    '=' , '==' => $itemValue == $value,
                    '===' => $itemValue === $value,
                    '!=' , '<>' => $itemValue != $value,
                    '!==' => $itemValue !== $value,
                    '>' => $itemValue > $value,
                    '>=' => $itemValue >= $value,
                    '<' => $itemValue < $value,
                    '<=' => $itemValue <= $value,
                    'in' => is_array($value) && in_array($itemValue, $value),
                    'not in' => is_array($value) && !in_array($itemValue, $value),
                    'like' => is_string($itemValue) && is_string($value) && str_contains($itemValue, $value),
                    default => false,
                };
            }));
        });

        // whereIn(array, key, values) - Filter where key in values
        $this->registerFunction('whereIn', function (array $array, string $key, array $values): array {
            return array_values(array_filter($array, function ($item) use ($key, $values) {
                return is_array($item) && isset($item[$key]) && in_array($item[$key], $values);
            }));
        });

        // whereNotIn(array, key, values) - Filter where key not in values
        $this->registerFunction('whereNotIn', function (array $array, string $key, array $values): array {
            return array_values(array_filter($array, function ($item) use ($key, $values) {
                return is_array($item) && isset($item[$key]) && !in_array($item[$key], $values);
            }));
        });

        // sortBy(array, key, direction?) - Sort by key
        $this->registerFunction('sortBy', function (array $array, string $key, string $direction = 'asc'): array {
            usort($array, function ($a, $b) use ($key, $direction) {
                $aVal = is_array($a) ? ($a[$key] ?? null) : null;
                $bVal = is_array($b) ? ($b[$key] ?? null) : null;
                $result = $aVal <=> $bVal;
                return $direction === 'desc' ? -$result : $result;
            });
            return $array;
        });

        // take(array, count) - Take first N elements
        $this->registerFunction('take', function (array $array, int $count): array {
            return array_slice($array, 0, $count);
        });

        // skip(array, count) - Skip first N elements
        $this->registerFunction('skip', function (array $array, int $count): array {
            return array_slice($array, $count);
        });

        // isEmpty(array) - Check if array is empty
        $this->registerFunction('arrayIsEmpty', function (array $array): bool {
            return empty($array);
        });

        // isNotEmpty(array) - Check if array is not empty
        $this->registerFunction('arrayIsNotEmpty', function (array $array): bool {
            return !empty($array);
        });

        // implode(array, separator?) - Join array to string
        $this->registerFunction('implode', function (array $array, string $separator = ','): string {
            return implode($separator, $array);
        });

        // diff(array1, array2) - Array difference
        $this->registerFunction('diff', function (array $array1, array $array2): array {
            return array_values(array_diff($array1, $array2));
        });

        // intersect(array1, array2) - Array intersection
        $this->registerFunction('intersect', function (array $array1, array $array2): array {
            return array_values(array_intersect($array1, $array2));
        });

        // combine(keys, values) - Combine arrays
        $this->registerFunction('combine', function (array $keys, array $values): array {
            if (count($keys) !== count($values)) {
                return [];
            }
            return array_combine($keys, $values);
        });

        // flip(array) - Flip keys and values
        $this->registerFunction('flip', function (array $array): array {
            return array_flip($array);
        });

        // range(start, end, step?) - Generate range
        $this->registerFunction('arrayRange', function (int|float $start, int|float $end, int|float $step = 1): array {
            return range($start, $end, $step);
        });

        // fill(value, count) - Fill array with value
        $this->registerFunction('fill', function (mixed $value, int $count): array {
            return array_fill(0, max(0, $count), $value);
        });
    }

    /**
     * Flatten array helper | 数组扁平化辅助方法
     *
     * @param array $array
     * @param int $depth
     * @param int $currentDepth
     * @return array
     */
    private function flattenArray(array $array, int $depth, int $currentDepth = 0): array
    {
        $result = [];
        foreach ($array as $item) {
            if (is_array($item) && ($depth < 0 || $currentDepth < $depth)) {
                $result = array_merge($result, $this->flattenArray($item, $depth, $currentDepth + 1));
            } else {
                $result[] = $item;
            }
        }
        return $result;
    }
}
