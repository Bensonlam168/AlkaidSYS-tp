<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Definition\Interfaces;

/**
 * 工作流连接接口
 *
 * 定义工作流中节点之间的连接/边。
 *
 * @package Alkaid\Workflow\Engine\Definition\Interfaces
 */
interface WorkflowConnectionInterface
{
    /**
     * 连接类型：顺序流
     */
    public const TYPE_SEQUENCE = 'sequence';

    /**
     * 连接类型：条件流
     */
    public const TYPE_CONDITIONAL = 'conditional';

    /**
     * 连接类型：默认流
     */
    public const TYPE_DEFAULT = 'default';

    /**
     * 连接类型：消息流
     */
    public const TYPE_MESSAGE = 'message';

    /**
     * 获取连接ID
     *
     * @return string
     */
    public function getId(): string;

    /**
     * 获取连接名称
     *
     * @return string
     */
    public function getName(): string;

    /**
     * 获取连接类型
     *
     * @return string
     */
    public function getType(): string;

    /**
     * 获取源节点ID
     *
     * @return string
     */
    public function getSourceNodeId(): string;

    /**
     * 获取目标节点ID
     *
     * @return string
     */
    public function getTargetNodeId(): string;

    /**
     * 获取条件表达式
     *
     * @return string|null
     */
    public function getCondition(): ?string;

    /**
     * 设置条件表达式
     *
     * @param string|null $condition 条件表达式
     * @return static
     */
    public function setCondition(?string $condition): static;

    /**
     * 获取优先级
     *
     * @return int
     */
    public function getPriority(): int;

    /**
     * 设置优先级
     *
     * @param int $priority 优先级
     * @return static
     */
    public function setPriority(int $priority): static;

    /**
     * 是否为默认连接
     *
     * @return bool
     */
    public function isDefault(): bool;

    /**
     * 设置为默认连接
     *
     * @param bool $default 是否默认
     * @return static
     */
    public function setDefault(bool $default): static;

    /**
     * 获取标签文本
     *
     * @return string|null
     */
    public function getLabel(): ?string;

    /**
     * 设置标签文本
     *
     * @param string|null $label 标签
     * @return static
     */
    public function setLabel(?string $label): static;

    /**
     * 获取元数据
     *
     * @return array
     */
    public function getMetadata(): array;

    /**
     * 设置元数据
     *
     * @param array $metadata 元数据
     * @return static
     */
    public function setMetadata(array $metadata): static;

    /**
     * 转换为数组
     *
     * @return array
     */
    public function toArray(): array;

    /**
     * 从数组创建
     *
     * @param array $data 数据数组
     * @return static
     */
    public static function fromArray(array $data): static;
}
