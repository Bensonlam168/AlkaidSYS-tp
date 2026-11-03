<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Expression\Functions;

/**
 * Math Functions Provider | 数学函数提供器
 *
 * Provides mathematical functions for expressions.
 * 为表达式提供数学计算函数。
 *
 * Available functions:
 * - abs(number) - Absolute value
 * - ceil(number) - Round up
 * - floor(number) - Round down
 * - round(number, precision?) - Round to precision
 * - max(a, b, ...) - Maximum value
 * - min(a, b, ...) - Minimum value
 * - sum(array) - Sum of array
 * - avg(array) - Average of array
 * - pow(base, exp) - Power
 * - sqrt(number) - Square root
 * - mod(dividend, divisor) - Modulo
 * - rand(min?, max?) - Random number
 * - clamp(value, min, max) - Clamp value to range
 * - percentage(value, total) - Calculate percentage
 * - percentageOf(percentage, total) - Get percentage of total
 * - isPositive(number) - Check if positive
 * - isNegative(number) - Check if negative
 * - isZero(number) - Check if zero
 * - isEven(number) - Check if even
 * - isOdd(number) - Check if odd
 * - format(number, decimals?, decPoint?, thousandsSep?) - Format number
 * - money(amount, currency?, locale?) - Format as money
 *
 * @package Alkaid\Workflow\Engine\Expression\Functions
 */
class MathFunctions extends AbstractFunctionProvider
{
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->registerMathFunctions();
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'math';
    }

    /**
     * {@inheritdoc}
     */
    public function getDescription(): string
    {
        return '数学计算函数';
    }

    /**
     * Register math functions | 注册数学函数
     *
     * @return void
     */
    private function registerMathFunctions(): void
    {
        // abs(number) - Absolute value
        $this->registerFunction('abs', function (int|float $number): int|float {
            return abs($number);
        });

        // ceil(number) - Round up
        $this->registerFunction('ceil', function (int|float $number): float {
            return ceil($number);
        });

        // floor(number) - Round down
        $this->registerFunction('floor', function (int|float $number): float {
            return floor($number);
        });

        // round(number, precision?) - Round to precision
        $this->registerFunction('round', function (int|float $number, int $precision = 0): float {
            return round($number, $precision);
        });

        // max(...values) - Maximum value
        $this->registerFunction('max', function (...$values): int|float {
            // Handle array argument
            if (count($values) === 1 && is_array($values[0])) {
                $values = $values[0];
            }
            return max($values);
        });

        // min(...values) - Minimum value
        $this->registerFunction('min', function (...$values): int|float {
            // Handle array argument
            if (count($values) === 1 && is_array($values[0])) {
                $values = $values[0];
            }
            return min($values);
        });

        // sum(array) - Sum of array
        $this->registerFunction('sum', function (array $values): int|float {
            return array_sum($values);
        });

        // avg(array) - Average of array
        $this->registerFunction('avg', function (array $values): float {
            if (empty($values)) {
                return 0.0;
            }
            return array_sum($values) / count($values);
        });

        // pow(base, exp) - Power
        $this->registerFunction('pow', function (int|float $base, int|float $exp): int|float {
            return pow($base, $exp);
        });

        // sqrt(number) - Square root
        $this->registerFunction('sqrt', function (int|float $number): float {
            return sqrt(max(0, $number));
        });

        // mod(dividend, divisor) - Modulo
        $this->registerFunction('mod', function (int $dividend, int $divisor): int {
            if ($divisor === 0) {
                return 0;
            }
            return $dividend % $divisor;
        });

        // rand(min?, max?) - Random number
        $this->registerFunction('rand', function (int $min = 0, int $max = PHP_INT_MAX): int {
            return mt_rand($min, $max);
        });

        // clamp(value, min, max) - Clamp value to range
        $this->registerFunction('clamp', function (int|float $value, int|float $min, int|float $max): int|float {
            return max($min, min($max, $value));
        });

        // percentage(value, total) - Calculate percentage
        $this->registerFunction('percentage', function (int|float $value, int|float $total): float {
            if ($total == 0) {
                return 0.0;
            }
            return ($value / $total) * 100;
        });

        // percentageOf(percentage, total) - Get percentage of total
        $this->registerFunction('percentageOf', function (int|float $percentage, int|float $total): float {
            return ($percentage / 100) * $total;
        });

        // isPositive(number) - Check if positive
        $this->registerFunction('isPositive', function (int|float $number): bool {
            return $number > 0;
        });

        // isNegative(number) - Check if negative
        $this->registerFunction('isNegative', function (int|float $number): bool {
            return $number < 0;
        });

        // isZero(number) - Check if zero
        $this->registerFunction('isZero', function (int|float $number): bool {
            return $number == 0;
        });

        // isEven(number) - Check if even
        $this->registerFunction('isEven', function (int $number): bool {
            return $number % 2 === 0;
        });

        // isOdd(number) - Check if odd
        $this->registerFunction('isOdd', function (int $number): bool {
            return $number % 2 !== 0;
        });

        // format(number, decimals?, decPoint?, thousandsSep?) - Format number
        $this->registerFunction('format', function (
            int|float $number,
            int $decimals = 2,
            string $decPoint = '.',
            string $thousandsSep = ','
        ): string {
            return number_format($number, $decimals, $decPoint, $thousandsSep);
        });

        // money(amount, currency?, locale?) - Format as money
        $this->registerFunction('money', function (
            int|float $amount,
            string $currency = 'CNY',
            string $locale = 'zh_CN'
        ): string {
            $symbols = [
                'CNY' => '¥',
                'USD' => '$',
                'EUR' => '€',
                'GBP' => '£',
                'JPY' => '¥',
            ];
            $symbol = $symbols[$currency] ?? $currency;
            $formatted = number_format($amount, 2, '.', ',');
            return $symbol . $formatted;
        });

        // range(start, end, step?) - Generate range
        $this->registerFunction('range', function (int $start, int $end, int $step = 1): array {
            return range($start, $end, $step);
        });

        // log(number, base?) - Logarithm
        $this->registerFunction('log', function (int|float $number, int|float $base = M_E): float {
            if ($number <= 0) {
                return 0.0;
            }
            return log($number, $base);
        });

        // log10(number) - Base-10 logarithm
        $this->registerFunction('log10', function (int|float $number): float {
            if ($number <= 0) {
                return 0.0;
            }
            return log10($number);
        });

        // exp(number) - Exponential
        $this->registerFunction('exp', function (int|float $number): float {
            return exp($number);
        });

        // sin(radians) - Sine
        $this->registerFunction('sin', function (int|float $radians): float {
            return sin($radians);
        });

        // cos(radians) - Cosine
        $this->registerFunction('cos', function (int|float $radians): float {
            return cos($radians);
        });

        // tan(radians) - Tangent
        $this->registerFunction('tan', function (int|float $radians): float {
            return tan($radians);
        });

        // toRadians(degrees) - Convert degrees to radians
        $this->registerFunction('toRadians', function (int|float $degrees): float {
            return deg2rad($degrees);
        });

        // toDegrees(radians) - Convert radians to degrees
        $this->registerFunction('toDegrees', function (int|float $radians): float {
            return rad2deg($radians);
        });
    }
}
