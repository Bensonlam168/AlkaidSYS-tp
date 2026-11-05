<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Definition;

use Alkaid\Workflow\Engine\ex\LFlowException;

/**
 * LogicFlow 序列化异常
 *
 * 只在「无法忠实转换」时抛出：主应用节点类型/子类型在引擎的 8 个节点解析器键里找不到
 * 语义等价物，或 LogicFlow 输入缺少必需字段、指向不认识的节点类型。
 *
 * 为什么不静默降级：
 * - 引擎 `ModelParser::parse()` 对**未注册**的节点类型是「静默跳过」（src/parser/ModelParser.php:81-97），
 *   节点会从 ProcessModel 里消失，运行时表现为「流程走到一半没有下一步」；
 * - 把语义不同的节点塞进 `custom` 会变成「调用自定义类方法」节点，行为完全不同。
 * 两种静默都比一个能读懂的异常更贵，因此这里一律 fail-loud。
 *
 * @package Alkaid\Workflow\Engine\Definition
 */
class LogicFlowSerializationException extends LFlowException
{
}
