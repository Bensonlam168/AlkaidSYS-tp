<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Definition\Interfaces;

use Alkaid\Workflow\Engine\Nodes\Interfaces\NodeInterface;

/**
 * 工作流定义接口
 *
 * 定义工作流的结构和行为，包括节点、连接、元数据等。
 *
 * @package Alkaid\Workflow\Engine\Definition\Interfaces
 */
interface WorkflowDefinitionInterface
{
    /**
     * 工作流状态：草稿
     */
    public const STATUS_DRAFT = 'draft';

    /**
     * 工作流状态：已发布
     */
    public const STATUS_PUBLISHED = 'published';

    /**
     * 工作流状态：已归档
     */
    public const STATUS_ARCHIVED = 'archived';

    /**
     * 工作流状态：已禁用
     */
    public const STATUS_DISABLED = 'disabled';

    /**
     * 工作流类型：审批流
     */
    public const TYPE_APPROVAL = 'approval';

    /**
     * 工作流类型：自动化流
     */
    public const TYPE_AUTOMATION = 'automation';

    /**
     * 工作流类型：业务流程
     */
    public const TYPE_BUSINESS = 'business';

    /**
     * 工作流类型：集成流
     */
    public const TYPE_INTEGRATION = 'integration';

    /**
     * 获取工作流ID
     *
     * @return string
     */
    public function getId(): string;

    /**
     * 获取工作流名称
     *
     * @return string
     */
    public function getName(): string;

    /**
     * 获取工作流显示名称
     *
     * @return string
     */
    public function getDisplayName(): string;

    /**
     * 获取工作流描述
     *
     * @return string
     */
    public function getDescription(): string;

    /**
     * 获取工作流类型
     *
     * @return string
     */
    public function getType(): string;

    /**
     * 获取工作流状态
     *
     * @return string
     */
    public function getStatus(): string;

    /**
     * 获取版本号
     *
     * @return int
     */
    public function getVersion(): int;

    /**
     * 获取租户ID
     *
     * @return int|null
     */
    public function getTenantId(): ?int;

    /**
     * 添加节点
     *
     * @param NodeInterface $node 节点
     * @return static
     */
    public function addNode(NodeInterface $node): static;

    /**
     * 移除节点
     *
     * @param string $nodeId 节点ID
     * @return static
     */
    public function removeNode(string $nodeId): static;

    /**
     * 获取节点
     *
     * @param string $nodeId 节点ID
     * @return NodeInterface|null
     */
    public function getNode(string $nodeId): ?NodeInterface;

    /**
     * 获取所有节点
     *
     * @return array<string, NodeInterface>
     */
    public function getNodes(): array;

    /**
     * 检查节点是否存在
     *
     * @param string $nodeId 节点ID
     * @return bool
     */
    public function hasNode(string $nodeId): bool;

    /**
     * 添加连接
     *
     * @param WorkflowConnectionInterface $connection 连接
     * @return static
     */
    public function addConnection(WorkflowConnectionInterface $connection): static;

    /**
     * 移除连接
     *
     * @param string $connectionId 连接ID
     * @return static
     */
    public function removeConnection(string $connectionId): static;

    /**
     * 获取连接
     *
     * @param string $connectionId 连接ID
     * @return WorkflowConnectionInterface|null
     */
    public function getConnection(string $connectionId): ?WorkflowConnectionInterface;

    /**
     * 获取所有连接
     *
     * @return array<string, WorkflowConnectionInterface>
     */
    public function getConnections(): array;

    /**
     * 获取开始节点
     *
     * @return NodeInterface|null
     */
    public function getStartNode(): ?NodeInterface;

    /**
     * 获取结束节点列表
     *
     * @return array<NodeInterface>
     */
    public function getEndNodes(): array;

    /**
     * 获取节点的输出连接
     *
     * @param string $nodeId 节点ID
     * @return array<WorkflowConnectionInterface>
     */
    public function getNodeOutputConnections(string $nodeId): array;

    /**
     * 获取节点的输入连接
     *
     * @param string $nodeId 节点ID
     * @return array<WorkflowConnectionInterface>
     */
    public function getNodeInputConnections(string $nodeId): array;

    /**
     * 获取节点的下一个节点
     *
     * @param string $nodeId 节点ID
     * @return array<NodeInterface>
     */
    public function getNextNodes(string $nodeId): array;

    /**
     * 获取节点的上一个节点
     *
     * @param string $nodeId 节点ID
     * @return array<NodeInterface>
     */
    public function getPreviousNodes(string $nodeId): array;

    /**
     * 验证工作流定义
     *
     * @return array 验证错误列表，空数组表示验证通过
     */
    public function validate(): array;

    /**
     * 检查工作流是否有效
     *
     * @return bool
     */
    public function isValid(): bool;

    /**
     * 发布工作流
     *
     * @return static
     */
    public function publish(): static;

    /**
     * 归档工作流
     *
     * @return static
     */
    public function archive(): static;

    /**
     * 禁用工作流
     *
     * @return static
     */
    public function disable(): static;

    /**
     * 克隆工作流定义
     *
     * @param string|null $newId 新ID
     * @return static
     */
    public function clone(?string $newId = null): static;

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

    /**
     * 转换为JSON
     *
     * @return string
     */
    public function toJson(): string;

    /**
     * 从JSON创建
     *
     * @param string $json JSON字符串
     * @return static
     */
    public static function fromJson(string $json): static;
}
