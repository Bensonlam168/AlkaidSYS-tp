<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Triggers\Interfaces;

use Alkaid\Workflow\Engine\Triggers\TriggerContext;
use Alkaid\Workflow\Engine\Triggers\TriggerResult;

/**
 * 工作流触发器接口
 *
 * 定义触发器的标准行为，支持多种触发类型：
 * - manual: 手动触发
 * - webhook: Webhook 触发
 * - schedule: 定时触发
 * - data_event: 数据事件触发
 * - form_submit: 表单提交触发
 * - message: 消息触发
 * - signal: 信号触发（BPMN）
 * - timer_event: 定时器事件触发
 * - system_event: 系统事件触发
 *
 * @package Alkaid\Workflow\Engine\Triggers\Interfaces
 */
interface TriggerInterface
{
    /**
     * 触发器类型常量
     */
    public const TYPE_MANUAL = 'manual';
    public const TYPE_WEBHOOK = 'webhook';
    public const TYPE_SCHEDULE = 'schedule';
    public const TYPE_DATA_EVENT = 'data_event';
    public const TYPE_FORM_SUBMIT = 'form_submit';
    public const TYPE_MESSAGE = 'message';
    public const TYPE_SIGNAL = 'signal';
    public const TYPE_TIMER_EVENT = 'timer_event';
    public const TYPE_SYSTEM_EVENT = 'system_event';

    /**
     * 获取触发器类型
     *
     * @return string 触发器类型标识
     */
    public function getType(): string;

    /**
     * 获取触发器名称
     *
     * @return string 可读的触发器名称
     */
    public function getName(): string;

    /**
     * 获取触发器描述
     *
     * @return string 触发器描述信息
     */
    public function getDescription(): string;

    /**
     * 获取配置 Schema
     *
     * 返回触发器配置的 JSON Schema 结构，用于配置验证和前端表单生成
     *
     * @return array 配置 Schema
     */
    public function getConfigSchema(): array;

    /**
     * 验证触发器配置
     *
     * @param array $config 配置数据
     * @return bool 验证是否通过
     */
    public function validateConfig(array $config): bool;

    /**
     * 获取配置验证错误信息
     *
     * @return array 错误信息列表
     */
    public function getValidationErrors(): array;

    /**
     * 注册触发器
     *
     * 当工作流启用时调用，用于设置触发器（如注册定时任务、Webhook 端点等）
     *
     * @param string $workflowId 工作流ID
     * @param array $config 触发器配置
     * @return void
     */
    public function register(string $workflowId, array $config): void;

    /**
     * 取消注册触发器
     *
     * 当工作流禁用或删除时调用，用于清理触发器资源
     *
     * @param string $workflowId 工作流ID
     * @return void
     */
    public function unregister(string $workflowId): void;

    /**
     * 检查是否应该触发
     *
     * 根据上下文判断是否满足触发条件
     *
     * @param TriggerContext $context 触发上下文
     * @return bool 是否应该触发
     */
    public function shouldTrigger(TriggerContext $context): bool;

    /**
     * 执行触发
     *
     * 执行触发操作，返回触发结果
     *
     * @param TriggerContext $context 触发上下文
     * @return TriggerResult 触发结果
     */
    public function trigger(TriggerContext $context): TriggerResult;

    /**
     * 检查触发器是否已注册
     *
     * @param string $workflowId 工作流ID
     * @return bool 是否已注册
     */
    public function isRegistered(string $workflowId): bool;

    /**
     * 获取已注册的工作流ID列表
     *
     * @return array 工作流ID列表
     */
    public function getRegisteredWorkflows(): array;
}
