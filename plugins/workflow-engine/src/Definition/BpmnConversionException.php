<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Definition;

use Alkaid\Workflow\Engine\ex\LFlowException;

/**
 * BPMN ⇄ LogicFlow 转换异常
 *
 * 只在「无法忠实转换」时抛出：
 * - 导入方向：BPMN 里出现子集之外的元素（`subProcess` / `boundaryEvent` / `inclusiveGateway` …）、
 *   id 重复、连线指向未声明节点、条件表达式无法桥接成引擎可 eval 的 PHP 表达式；
 * - 导出方向：LogicFlow 节点类型在 BPMN 里没有对应元素（`custom` / `wfSubProcess`）。
 *
 * 为什么不静默降级：静默丢弃一个元素 = 悄悄改变图。导入侧典型后果是
 * 「分支网关被吞掉，两条分支拍成一条直线」；导出侧典型后果是
 * 「导出的 BPMN 少了节点，别人在 bpmn.io 里看到的是另一个流程」。
 * 两者都会在很久以后以「流程走错了」的形式暴露，比一个能读懂的异常贵得多。
 *
 * 为什么继承 {@see LFlowException} 而不是 {@see LogicFlowSerializationException}：
 * 后者描述的是「主应用 WorkflowDefinition ⇄ LogicFlow」的映射失败，方向不同；
 * 两者共同的祖先是 `LFlowException`，调用方 catch 它即可同时兜住两个方向。
 *
 * @package Alkaid\Workflow\Engine\Definition
 */
class BpmnConversionException extends LFlowException
{
}
