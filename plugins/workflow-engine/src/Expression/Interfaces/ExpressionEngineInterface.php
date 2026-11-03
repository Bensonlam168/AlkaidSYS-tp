<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Expression\Interfaces;

/**
 * Expression Engine Interface | 表达式引擎接口
 *
 * Defines the contract for workflow expression evaluation.
 * 定义工作流表达式评估的契约。
 *
 * @package Alkaid\Workflow\Engine\Expression\Interfaces
 */
interface ExpressionEngineInterface
{
    /**
     * Evaluate an expression with context | 在上下文中评估表达式
     *
     * @param string $expression The expression to evaluate
     * @param array $context The context data for evaluation
     * @return mixed The evaluation result
     */
    public function evaluate(string $expression, array $context = []): mixed;

    /**
     * Evaluate an expression and return boolean | 评估表达式并返回布尔值
     *
     * @param string $expression The expression to evaluate
     * @param array $context The context data for evaluation
     * @return bool The boolean result
     */
    public function evaluateCondition(string $expression, array $context = []): bool;

    /**
     * Validate an expression syntax | 验证表达式语法
     *
     * @param string $expression The expression to validate
     * @return bool True if valid, false otherwise
     */
    public function validate(string $expression): bool;

    /**
     * Compile an expression for later use | 编译表达式供后续使用
     *
     * @param string $expression The expression to compile
     * @return mixed The compiled expression
     */
    public function compile(string $expression): mixed;

    /**
     * Parse and extract variables from expression | 解析并提取表达式中的变量
     *
     * @param string $expression The expression to parse
     * @return array List of variable names found in the expression
     */
    public function extractVariables(string $expression): array;

    /**
     * Register a custom function | 注册自定义函数
     *
     * @param string $name Function name
     * @param callable $function The function implementation
     * @param callable|null $compiler Optional compiler for the function
     * @return void
     */
    public function registerFunction(string $name, callable $function, ?callable $compiler = null): void;

    /**
     * Register a function provider | 注册函数提供器
     *
     * @param ExpressionFunctionProviderInterface $provider The function provider
     * @return void
     */
    public function registerProvider(ExpressionFunctionProviderInterface $provider): void;

    /**
     * Get all registered function names | 获取所有已注册的函数名
     *
     * @return array List of function names
     */
    public function getRegisteredFunctions(): array;

    /**
     * Clear expression cache | 清除表达式缓存
     *
     * @return void
     */
    public function clearCache(): void;

    /**
     * Get cache statistics | 获取缓存统计
     *
     * @return array Cache statistics
     */
    public function getCacheStats(): array;
}
