<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Nodes\Interfaces;

use Alkaid\Workflow\Engine\Nodes\NodeResult;
use Alkaid\Workflow\Engine\Swoole\Execution\ExecutionContext;

/**
 * 工作流节点接口
 *
 * 定义所有工作流节点必须实现的标准接口。
 * 节点是工作流的基本执行单元，每个节点负责特定的业务逻辑。
 *
 * @package Alkaid\Workflow\Engine\Nodes\Interfaces
 */
interface NodeInterface
{
    /**
     * 节点类型常量
     */
    public const TYPE_START = 'start';
    public const TYPE_END = 'end';
    public const TYPE_TASK = 'task';
    public const TYPE_GATEWAY = 'gateway';
    public const TYPE_SUBPROCESS = 'subprocess';
    public const TYPE_EVENT = 'event';
    public const TYPE_CUSTOM = 'custom';

    /**
     * 网关子类型
     */
    public const GATEWAY_EXCLUSIVE = 'exclusive';    // 排他网关（决策）
    public const GATEWAY_PARALLEL = 'parallel';      // 并行网关（分支/汇聚）
    public const GATEWAY_INCLUSIVE = 'inclusive';    // 包容网关
    public const GATEWAY_EVENT = 'event_based';      // 事件网关

    /**
     * 任务子类型
     */
    public const TASK_USER = 'user';                 // 用户任务
    public const TASK_SERVICE = 'service';           // 服务任务
    public const TASK_SCRIPT = 'script';             // 脚本任务
    public const TASK_SEND = 'send';                 // 发送任务
    public const TASK_RECEIVE = 'receive';           // 接收任务
    public const TASK_MANUAL = 'manual';             // 手动任务
    public const TASK_BUSINESS_RULE = 'business_rule'; // 业务规则任务

    /**
     * 获取节点ID
     *
     * @return string
     */
    public function getId(): string;

    /**
     * 获取节点名称
     *
     * @return string
     */
    public function getName(): string;

    /**
     * 获取节点显示名称
     *
     * @return string
     */
    public function getDisplayName(): string;

    /**
     * 获取节点类型
     *
     * @return string 返回 TYPE_* 常量之一
     */
    public function getType(): string;

    /**
     * 获取节点子类型
     *
     * @return string|null 返回子类型，如 GATEWAY_* 或 TASK_*
     */
    public function getSubType(): ?string;

    /**
     * 获取节点描述
     *
     * @return string
     */
    public function getDescription(): string;

    /**
     * 执行节点
     *
     * @param ExecutionContext $context 执行上下文
     * @return NodeResult 执行结果
     */
    public function execute(ExecutionContext $context): NodeResult;

    /**
     * 检查节点是否可执行
     *
     * 在执行前检查前置条件是否满足
     *
     * @param ExecutionContext $context 执行上下文
     * @return bool
     */
    public function canExecute(ExecutionContext $context): bool;

    /**
     * 获取节点配置
     *
     * @return array
     */
    public function getConfig(): array;

    /**
     * 设置节点配置
     *
     * @param array $config 配置数组
     * @return static
     */
    public function setConfig(array $config): static;

    /**
     * 验证节点配置
     *
     * @param array $config 可选的配置数组，如果为空则验证当前配置
     * @return bool
     */
    public function validateConfig(array $config = []): bool;

    /**
     * 获取配置验证错误
     *
     * @return array
     */
    public function getValidationErrors(): array;

    /**
     * 获取节点配置 Schema
     *
     * 用于前端动态表单生成
     *
     * @return array JSON Schema 格式的配置定义
     */
    public function getConfigSchema(): array;

    /**
     * 获取输入连接
     *
     * @return array
     */
    public function getInputs(): array;

    /**
     * 设置输入连接
     *
     * @param array $inputs 输入连接数组
     * @return static
     */
    public function setInputs(array $inputs): static;

    /**
     * 获取输出连接
     *
     * @return array
     */
    public function getOutputs(): array;

    /**
     * 设置输出连接
     *
     * @param array $outputs 输出连接数组
     * @return static
     */
    public function setOutputs(array $outputs): static;

    /**
     * 获取节点元数据
     *
     * @param string|null $key 元数据键，为空返回全部
     * @param mixed $default 默认值
     * @return mixed
     */
    public function getMeta(?string $key = null, mixed $default = null): mixed;

    /**
     * 设置节点元数据
     *
     * @param string $key 元数据键
     * @param mixed $value 元数据值
     * @return static
     */
    public function setMeta(string $key, mixed $value): static;

    /**
     * 将节点转换为数组
     *
     * @return array
     */
    public function toArray(): array;

    /**
     * 从数组创建节点
     *
     * @param array $data 节点数据
     * @return static
     */
    public static function fromArray(array $data): static;
}
