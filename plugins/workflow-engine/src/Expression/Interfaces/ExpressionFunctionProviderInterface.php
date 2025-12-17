<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Expression\Interfaces;

/**
 * Expression Function Provider Interface | 表达式函数提供器接口
 *
 * @package Alkaid\Workflow\Engine\Expression\Interfaces
 */
interface ExpressionFunctionProviderInterface
{
    /**
     * Get all functions provided by this provider | 获取此提供器提供的所有函数
     *
     * @return array Array of function definitions
     *               Each item should have:
     *               - 'name': string - Function name
     *               - 'compiler': callable - Compiler function
     *               - 'evaluator': callable - Evaluator function
     */
    public function getFunctions(): array;

    /**
     * Get provider name | 获取提供器名称
     *
     * @return string Provider name
     */
    public function getName(): string;

    /**
     * Get provider description | 获取提供器描述
     *
     * @return string Provider description
     */
    public function getDescription(): string;
}
