<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Expression\Functions;

use Alkaid\Workflow\Engine\Expression\Interfaces\ExpressionFunctionProviderInterface;

/**
 * Abstract Function Provider | 抽象函数提供器
 *
 * Base class for expression function providers.
 * 表达式函数提供器的基类。
 *
 * @package Alkaid\Workflow\Engine\Expression\Functions
 */
abstract class AbstractFunctionProvider implements ExpressionFunctionProviderInterface
{
    /**
     * Registered functions
     *
     * @var array
     */
    protected array $functions = [];

    /**
     * {@inheritdoc}
     */
    public function getFunctions(): array
    {
        return $this->functions;
    }

    /**
     * Register a function | 注册函数
     *
     * @param string $name Function name
     * @param callable $evaluator Evaluator function
     * @param callable|null $compiler Optional compiler function
     * @return void
     */
    protected function registerFunction(string $name, callable $evaluator, ?callable $compiler = null): void
    {
        $this->functions[] = [
            'name' => $name,
            'evaluator' => $evaluator,
            'compiler' => $compiler ?? $this->createDefaultCompiler($name),
        ];
    }

    /**
     * Create default compiler for function | 为函数创建默认编译器
     *
     * @param string $name Function name
     * @return callable
     */
    protected function createDefaultCompiler(string $name): callable
    {
        return function (...$args) use ($name) {
            return sprintf('%s(%s)', $name, implode(', ', $args));
        };
    }
}
