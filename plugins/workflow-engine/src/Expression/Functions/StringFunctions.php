<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Expression\Functions;

/**
 * String Functions Provider | 字符串函数提供器
 *
 * Provides string manipulation functions for expressions.
 * 为表达式提供字符串操作函数。
 *
 * Available functions:
 * - strlen(string) - String length
 * - substr(string, start, length?) - Substring
 * - strpos(haystack, needle) - Find position
 * - upper(string) - Uppercase
 * - lower(string) - Lowercase
 * - ucfirst(string) - Capitalize first letter
 * - ucwords(string) - Capitalize words
 * - trim(string) - Trim whitespace
 * - ltrim(string) - Left trim
 * - rtrim(string) - Right trim
 * - replace(search, replace, subject) - Replace
 * - concat(...strings) - Concatenate
 * - join(array, separator?) - Join array
 * - split(string, separator) - Split string
 * - contains(haystack, needle) - Check if contains
 * - startsWith(string, prefix) - Check if starts with
 * - endsWith(string, suffix) - Check if ends with
 * - isEmpty(string) - Check if empty
 * - isNotEmpty(string) - Check if not empty
 * - repeat(string, times) - Repeat string
 * - reverse(string) - Reverse string
 * - pad(string, length, padString?, padType?) - Pad string
 * - truncate(string, length, suffix?) - Truncate string
 * - slug(string) - Create URL slug
 * - camel(string) - Convert to camelCase
 * - snake(string) - Convert to snake_case
 * - kebab(string) - Convert to kebab-case
 * - mask(string, start?, length?, char?) - Mask characters
 * - match(string, pattern) - Regex match
 * - matchAll(string, pattern) - Regex match all
 *
 * @package Alkaid\Workflow\Engine\Expression\Functions
 */
class StringFunctions extends AbstractFunctionProvider
{
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->registerStringFunctions();
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'string';
    }

    /**
     * {@inheritdoc}
     */
    public function getDescription(): string
    {
        return '字符串处理函数';
    }

    /**
     * Register string functions | 注册字符串函数
     *
     * @return void
     */
    private function registerStringFunctions(): void
    {
        // strlen(string) - String length
        $this->registerFunction('strlen', function (string $string): int {
            return mb_strlen($string);
        });

        // substr(string, start, length?) - Substring
        $this->registerFunction('substr', function (string $string, int $start, ?int $length = null): string {
            return mb_substr($string, $start, $length);
        });

        // strpos(haystack, needle) - Find position
        $this->registerFunction('strpos', function (string $haystack, string $needle): int|false {
            return mb_strpos($haystack, $needle);
        });

        // upper(string) - Uppercase
        $this->registerFunction('upper', function (string $string): string {
            return mb_strtoupper($string);
        });

        // lower(string) - Lowercase
        $this->registerFunction('lower', function (string $string): string {
            return mb_strtolower($string);
        });

        // ucfirst(string) - Capitalize first letter
        $this->registerFunction('ucfirst', function (string $string): string {
            if ($string === '') {
                return $string;
            }
            return mb_strtoupper(mb_substr($string, 0, 1)) . mb_substr($string, 1);
        });

        // ucwords(string) - Capitalize words
        $this->registerFunction('ucwords', function (string $string): string {
            return mb_convert_case($string, MB_CASE_TITLE);
        });

        // trim(string) - Trim whitespace
        $this->registerFunction('trim', function (string $string): string {
            return trim($string);
        });

        // ltrim(string) - Left trim
        $this->registerFunction('ltrim', function (string $string): string {
            return ltrim($string);
        });

        // rtrim(string) - Right trim
        $this->registerFunction('rtrim', function (string $string): string {
            return rtrim($string);
        });

        // replace(search, replace, subject) - Replace
        $this->registerFunction('replace', function (string $search, string $replace, string $subject): string {
            return str_replace($search, $replace, $subject);
        });

        // concat(...strings) - Concatenate
        $this->registerFunction('concat', function (...$strings): string {
            return implode('', array_map('strval', $strings));
        });

        // join(array, separator?) - Join array
        $this->registerFunction('join', function (array $array, string $separator = ''): string {
            return implode($separator, $array);
        });

        // split(string, separator) - Split string
        $this->registerFunction('split', function (string $string, string $separator): array {
            if ($separator === '') {
                return mb_str_split($string);
            }
            return explode($separator, $string);
        });

        // strContains(haystack, needle) - Check if contains
        $this->registerFunction('strContains', function (string $haystack, string $needle): bool {
            return str_contains($haystack, $needle);
        });

        // startsWith(string, prefix) - Check if starts with
        $this->registerFunction('startsWith', function (string $string, string $prefix): bool {
            return str_starts_with($string, $prefix);
        });

        // endsWith(string, suffix) - Check if ends with
        $this->registerFunction('endsWith', function (string $string, string $suffix): bool {
            return str_ends_with($string, $suffix);
        });

        // isEmpty(value) - Check if empty
        $this->registerFunction('isEmpty', function (mixed $value): bool {
            return empty($value) || (is_string($value) && trim($value) === '');
        });

        // isNotEmpty(value) - Check if not empty
        $this->registerFunction('isNotEmpty', function (mixed $value): bool {
            return !empty($value) && !(is_string($value) && trim($value) === '');
        });

        // repeat(string, times) - Repeat string
        $this->registerFunction('repeat', function (string $string, int $times): string {
            return str_repeat($string, max(0, $times));
        });

        // reverse(string) - Reverse string
        $this->registerFunction('reverse', function (string $string): string {
            $chars = mb_str_split($string);
            return implode('', array_reverse($chars));
        });

        // pad(string, length, padString?, padType?) - Pad string
        $this->registerFunction('pad', function (
            string $string,
            int $length,
            string $padString = ' ',
            string $padType = 'right'
        ): string {
            $type = match ($padType) {
                'left' => STR_PAD_LEFT,
                'both' => STR_PAD_BOTH,
                default => STR_PAD_RIGHT,
            };
            return str_pad($string, $length, $padString, $type);
        });

        // truncate(string, length, suffix?) - Truncate string
        $this->registerFunction('truncate', function (string $string, int $length, string $suffix = '...'): string {
            if (mb_strlen($string) <= $length) {
                return $string;
            }
            return mb_substr($string, 0, $length - mb_strlen($suffix)) . $suffix;
        });

        // slug(string) - Create URL slug
        $this->registerFunction('slug', function (string $string): string {
            // Convert to lowercase
            $string = mb_strtolower($string);
            // Replace non-alphanumeric with hyphens
            $string = preg_replace('/[^a-z0-9\-]/', '-', $string);
            // Remove multiple hyphens
            $string = preg_replace('/-+/', '-', $string);
            // Trim hyphens
            return trim($string, '-');
        });

        // camel(string) - Convert to camelCase
        $this->registerFunction('camel', function (string $string): string {
            $string = preg_replace('/[^a-zA-Z0-9]+/', ' ', $string);
            $string = ucwords(strtolower($string));
            $string = str_replace(' ', '', $string);
            return lcfirst($string);
        });

        // snake(string) - Convert to snake_case
        $this->registerFunction('snake', function (string $string): string {
            $string = preg_replace('/(?<!^)[A-Z]/', '_$0', $string);
            $string = preg_replace('/[^a-zA-Z0-9]+/', '_', $string);
            return strtolower(trim($string, '_'));
        });

        // kebab(string) - Convert to kebab-case
        $this->registerFunction('kebab', function (string $string): string {
            $string = preg_replace('/(?<!^)[A-Z]/', '-$0', $string);
            $string = preg_replace('/[^a-zA-Z0-9]+/', '-', $string);
            return strtolower(trim($string, '-'));
        });

        // mask(string, start?, length?, char?) - Mask characters
        $this->registerFunction('mask', function (
            string $string,
            int $start = 0,
            ?int $length = null,
            string $char = '*'
        ): string {
            $strLen = mb_strlen($string);
            $length = $length ?? ($strLen - $start);

            if ($start >= $strLen) {
                return $string;
            }

            $masked = str_repeat($char, $length);
            return mb_substr($string, 0, $start) . $masked . mb_substr($string, $start + $length);
        });

        // match(string, pattern) - Regex match
        $this->registerFunction('match', function (string $string, string $pattern): ?array {
            if (preg_match($pattern, $string, $matches)) {
                return $matches;
            }
            return null;
        });

        // matchAll(string, pattern) - Regex match all
        $this->registerFunction('matchAll', function (string $string, string $pattern): array {
            preg_match_all($pattern, $string, $matches);
            return $matches[0] ?? [];
        });

        // format(template, ...args) - Format string with placeholders
        $this->registerFunction('format', function (string $template, ...$args): string {
            return vsprintf($template, $args);
        });

        // nl2br(string) - Convert newlines to <br>
        $this->registerFunction('nl2br', function (string $string): string {
            return nl2br($string);
        });

        // stripTags(string) - Remove HTML tags
        $this->registerFunction('stripTags', function (string $string): string {
            return strip_tags($string);
        });

        // htmlEncode(string) - HTML encode
        $this->registerFunction('htmlEncode', function (string $string): string {
            return htmlspecialchars($string, ENT_QUOTES | ENT_HTML5);
        });

        // htmlDecode(string) - HTML decode
        $this->registerFunction('htmlDecode', function (string $string): string {
            return htmlspecialchars_decode($string, ENT_QUOTES | ENT_HTML5);
        });

        // jsonEncode(value) - JSON encode
        $this->registerFunction('jsonEncode', function (mixed $value): string {
            return json_encode($value, JSON_UNESCAPED_UNICODE) ?: '{}';
        });

        // jsonDecode(string) - JSON decode
        $this->registerFunction('jsonDecode', function (string $string): mixed {
            return json_decode($string, true);
        });
    }
}
