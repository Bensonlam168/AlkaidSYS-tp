<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Expression;

use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\ExpressionLanguage\ExpressionFunction;
use Symfony\Component\ExpressionLanguage\SyntaxError;
use Alkaid\Workflow\Engine\Expression\Interfaces\ExpressionEngineInterface;
use Alkaid\Workflow\Engine\Expression\Interfaces\ExpressionFunctionProviderInterface;
use Alkaid\Workflow\Engine\Expression\Cache\ExpressionCache;
use Alkaid\Workflow\Engine\Expression\Context\ExpressionContext;
use Alkaid\Workflow\Engine\Expression\Functions\DateFunctions;
use Alkaid\Workflow\Engine\Expression\Functions\MathFunctions;
use Alkaid\Workflow\Engine\Expression\Functions\StringFunctions;
use Alkaid\Workflow\Engine\Expression\Functions\ArrayFunctions;
use Alkaid\Workflow\Engine\Expression\Functions\WorkflowFunctions;
use Throwable;

/**
 * Expression Engine | 表达式引擎
 *
 * Core expression evaluation engine using Symfony Expression Language.
 * 基于 Symfony Expression Language 的核心表达式评估引擎。
 *
 * Features:
 * - Expression evaluation with custom functions
 * - Expression validation and compilation
 * - LRU caching for compiled expressions
 * - Variable extraction from expressions
 * - Safe evaluation with error handling
 *
 * @package Alkaid\Workflow\Engine\Expression
 */
class ExpressionEngine implements ExpressionEngineInterface
{
    /**
     * Symfony Expression Language instance
     *
     * @var ExpressionLanguage
     */
    private ExpressionLanguage $expressionLanguage;

    /**
     * Expression cache
     *
     * @var ExpressionCache
     */
    private ExpressionCache $cache;

    /**
     * Registered function providers
     *
     * @var array<string, ExpressionFunctionProviderInterface>
     */
    private array $providers = [];

    /**
     * Registered functions (flat list)
     *
     * @var array<string, array{evaluator: callable, compiler: callable}>
     */
    private array $functions = [];

    /**
     * Workflow functions provider (special handling for runtime context)
     *
     * @var WorkflowFunctions|null
     */
    private ?WorkflowFunctions $workflowFunctions = null;

    /**
     * Enable strict mode (throws on errors)
     *
     * @var bool
     */
    private bool $strictMode = false;

    /**
     * Enable caching
     *
     * @var bool
     */
    private bool $cacheEnabled = true;

    /**
     * Constructor
     *
     * @param int $cacheMaxSize Maximum cache size
     * @param bool $registerDefaultProviders Whether to register default function providers
     */
    public function __construct(int $cacheMaxSize = 1000, bool $registerDefaultProviders = true)
    {
        $this->expressionLanguage = new ExpressionLanguage();
        $this->cache = new ExpressionCache($cacheMaxSize);

        if ($registerDefaultProviders) {
            $this->registerDefaultProviders();
        }
    }

    /**
     * Register default function providers | 注册默认函数提供器
     *
     * @return void
     */
    private function registerDefaultProviders(): void
    {
        // Register built-in function providers
        $this->registerProvider(new DateFunctions());
        $this->registerProvider(new MathFunctions());
        $this->registerProvider(new StringFunctions());
        $this->registerProvider(new ArrayFunctions());

        // Store workflow functions for special handling
        $this->workflowFunctions = new WorkflowFunctions();
        $this->registerProvider($this->workflowFunctions);
    }

    /**
     * {@inheritdoc}
     */
    public function evaluate(string $expression, array $context = []): mixed
    {
        if (empty(trim($expression))) {
            return null;
        }

        try {
            // Update workflow functions runtime context
            if ($this->workflowFunctions !== null) {
                $this->workflowFunctions->setRuntimeContext($context);
            }

            // Try to get from cache
            $cacheKey = ExpressionCache::generateKey($expression);

            if ($this->cacheEnabled && $this->cache->has($cacheKey)) {
                $cached = $this->cache->get($cacheKey);
                if ($cached !== null && isset($cached['parsed'])) {
                    // Re-evaluate cached parsed expression
                    return $this->expressionLanguage->evaluate(
                        $cached['parsed'],
                        $context
                    );
                }
            }

            // Evaluate and cache
            $result = $this->expressionLanguage->evaluate($expression, $context);

            if ($this->cacheEnabled) {
                $this->cache->set($cacheKey, [
                    'expression' => $expression,
                    'parsed' => $expression,
                ]);
            }

            return $result;
        } catch (SyntaxError $e) {
            if ($this->strictMode) {
                throw new \RuntimeException(
                    "Expression syntax error: {$e->getMessage()}",
                    0,
                    $e
                );
            }
            return null;
        } catch (Throwable $e) {
            if ($this->strictMode) {
                throw new \RuntimeException(
                    "Expression evaluation error: {$e->getMessage()}",
                    0,
                    $e
                );
            }
            return null;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function evaluateCondition(string $expression, array $context = []): bool
    {
        $result = $this->evaluate($expression, $context);
        return (bool) $result;
    }

    /**
     * Evaluate expression with ExpressionContext | 使用表达式上下文评估表达式
     *
     * @param string $expression Expression string
     * @param ExpressionContext $context Expression context
     * @return mixed
     */
    public function evaluateWithContext(string $expression, ExpressionContext $context): mixed
    {
        return $this->evaluate($expression, $context->toArray());
    }

    /**
     * {@inheritdoc}
     */
    public function validate(string $expression, array $allowedVariables = []): bool
    {
        if (empty(trim($expression))) {
            return false;
        }

        try {
            // Try to parse the expression
            $this->expressionLanguage->parse($expression, $allowedVariables);
            return true;
        } catch (SyntaxError $e) {
            return false;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function compile(string $expression): ?string
    {
        if (empty(trim($expression))) {
            return null;
        }

        try {
            // Try to get from cache
            $cacheKey = ExpressionCache::generateKey($expression) . ':compiled';

            if ($this->cacheEnabled && $this->cache->has($cacheKey)) {
                return $this->cache->get($cacheKey);
            }

            // Compile expression
            $compiled = $this->expressionLanguage->compile($expression);

            if ($this->cacheEnabled) {
                $this->cache->set($cacheKey, $compiled);
            }

            return $compiled;
        } catch (Throwable $e) {
            if ($this->strictMode) {
                throw new \RuntimeException(
                    "Expression compilation error: {$e->getMessage()}",
                    0,
                    $e
                );
            }
            return null;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function extractVariables(string $expression): array
    {
        if (empty(trim($expression))) {
            return [];
        }

        // Pattern to match variable names (identifiers)
        // This is a simplified extraction - matches word characters
        preg_match_all('/\b([a-zA-Z_][a-zA-Z0-9_]*)\b/', $expression, $matches);

        if (empty($matches[1])) {
            return [];
        }

        $candidates = array_unique($matches[1]);

        // Filter out known function names and keywords
        $excluded = array_merge(
            array_keys($this->functions),
            ['true', 'false', 'null', 'in', 'not', 'and', 'or', 'matches']
        );

        return array_values(array_diff($candidates, $excluded));
    }

    /**
     * {@inheritdoc}
     */
    public function registerFunction(string $name, callable $evaluator, ?callable $compiler = null): void
    {
        // Store function metadata
        $this->functions[$name] = [
            'evaluator' => $evaluator,
            'compiler' => $compiler ?? $this->createDefaultCompiler($name),
        ];

        // Register with Symfony Expression Language
        $this->expressionLanguage->addFunction(
            new ExpressionFunction(
                $name,
                $this->functions[$name]['compiler'],
                fn ($args, ...$params) => $evaluator(...$params)
            )
        );
    }

    /**
     * {@inheritdoc}
     */
    public function registerProvider(ExpressionFunctionProviderInterface $provider): void
    {
        $providerName = $provider->getName();
        $this->providers[$providerName] = $provider;

        // Register all functions from the provider
        foreach ($provider->getFunctions() as $function) {
            $name = $function['name'];
            $evaluator = $function['evaluator'];
            $compiler = $function['compiler'] ?? $this->createDefaultCompiler($name);

            $this->functions[$name] = [
                'evaluator' => $evaluator,
                'compiler' => $compiler,
            ];

            // Register with Symfony Expression Language
            $this->expressionLanguage->addFunction(
                new ExpressionFunction(
                    $name,
                    $compiler,
                    fn ($args, ...$params) => $evaluator(...$params)
                )
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getRegisteredFunctions(): array
    {
        return array_keys($this->functions);
    }

    /**
     * Get registered providers | 获取已注册的提供器
     *
     * @return array<string, ExpressionFunctionProviderInterface>
     */
    public function getRegisteredProviders(): array
    {
        return $this->providers;
    }

    /**
     * Check if function is registered | 检查函数是否已注册
     *
     * @param string $name Function name
     * @return bool
     */
    public function hasFunction(string $name): bool
    {
        return isset($this->functions[$name]);
    }

    /**
     * {@inheritdoc}
     */
    public function clearCache(): void
    {
        $this->cache->clear();
    }

    /**
     * {@inheritdoc}
     */
    public function getCacheStats(): array
    {
        return $this->cache->getStats();
    }

    /**
     * Enable strict mode | 启用严格模式
     *
     * @param bool $enabled
     * @return self
     */
    public function setStrictMode(bool $enabled): self
    {
        $this->strictMode = $enabled;
        return $this;
    }

    /**
     * Check if strict mode is enabled | 检查是否启用严格模式
     *
     * @return bool
     */
    public function isStrictMode(): bool
    {
        return $this->strictMode;
    }

    /**
     * Enable or disable caching | 启用或禁用缓存
     *
     * @param bool $enabled
     * @return self
     */
    public function setCacheEnabled(bool $enabled): self
    {
        $this->cacheEnabled = $enabled;
        return $this;
    }

    /**
     * Check if caching is enabled | 检查是否启用缓存
     *
     * @return bool
     */
    public function isCacheEnabled(): bool
    {
        return $this->cacheEnabled;
    }

    /**
     * Get validation error message | 获取验证错误信息
     *
     * @param string $expression
     * @return string|null
     */
    public function getValidationError(string $expression): ?string
    {
        try {
            $this->expressionLanguage->parse($expression, []);
            return null;
        } catch (SyntaxError $e) {
            return $e->getMessage();
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * Evaluate multiple expressions | 评估多个表达式
     *
     * @param array<string, string> $expressions Map of name => expression
     * @param array $context Context variables
     * @return array<string, mixed> Map of name => result
     */
    public function evaluateMultiple(array $expressions, array $context = []): array
    {
        $results = [];
        foreach ($expressions as $name => $expression) {
            $results[$name] = $this->evaluate($expression, $context);
        }
        return $results;
    }

    /**
     * Evaluate expression with fallback | 评估表达式，失败时返回回退值
     *
     * @param string $expression Expression to evaluate
     * @param array $context Context variables
     * @param mixed $fallback Fallback value if evaluation fails
     * @return mixed
     */
    public function evaluateWithFallback(string $expression, array $context, mixed $fallback): mixed
    {
        try {
            $result = $this->evaluate($expression, $context);
            return $result ?? $fallback;
        } catch (Throwable $e) {
            return $fallback;
        }
    }

    /**
     * Create default compiler for function | 为函数创建默认编译器
     *
     * @param string $name Function name
     * @return callable
     */
    private function createDefaultCompiler(string $name): callable
    {
        return function (...$args) use ($name) {
            return sprintf('%s(%s)', $name, implode(', ', $args));
        };
    }

    /**
     * Get function documentation | 获取函数文档
     *
     * @return array
     */
    public function getFunctionDocumentation(): array
    {
        $docs = [];
        foreach ($this->providers as $providerName => $provider) {
            $docs[$providerName] = [
                'name' => $provider->getName(),
                'description' => $provider->getDescription(),
                'functions' => array_map(
                    fn ($f) => $f['name'],
                    $provider->getFunctions()
                ),
            ];
        }
        return $docs;
    }

    /**
     * Test expression without side effects | 测试表达式（无副作用）
     *
     * @param string $expression
     * @param array $context
     * @return array{valid: bool, result: mixed, error: ?string}
     */
    public function testExpression(string $expression, array $context = []): array
    {
        try {
            $result = $this->evaluate($expression, $context);
            return [
                'valid' => true,
                'result' => $result,
                'error' => null,
            ];
        } catch (Throwable $e) {
            return [
                'valid' => false,
                'result' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Create a sandboxed evaluator | 创建沙箱评估器
     *
     * Returns a new instance with limited functions for safer evaluation.
     *
     * @param array $allowedFunctions List of allowed function names
     * @return self
     */
    public function createSandbox(array $allowedFunctions = []): self
    {
        $sandbox = new self(1000, false);

        // Only register allowed functions
        foreach ($allowedFunctions as $name) {
            if (isset($this->functions[$name])) {
                $sandbox->registerFunction(
                    $name,
                    $this->functions[$name]['evaluator'],
                    $this->functions[$name]['compiler']
                );
            }
        }

        return $sandbox;
    }
}
