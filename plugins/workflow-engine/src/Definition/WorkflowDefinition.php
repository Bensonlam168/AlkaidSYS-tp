<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Definition;

use Alkaid\Workflow\Engine\Definition\Interfaces\WorkflowConnectionInterface;
use Alkaid\Workflow\Engine\Definition\Interfaces\WorkflowDefinitionInterface;
use Alkaid\Workflow\Engine\Nodes\Interfaces\NodeInterface;
use Alkaid\Workflow\Engine\Nodes\NodeRegistry;

/**
 * 工作流定义
 *
 * 完整描述一个工作流的结构，包括节点、连接、元数据等。
 *
 * @package Alkaid\Workflow\Engine\Definition
 */
class WorkflowDefinition implements WorkflowDefinitionInterface
{
    /**
     * 工作流ID
     *
     * @var string
     */
    protected string $id;

    /**
     * 工作流名称
     *
     * @var string
     */
    protected string $name;

    /**
     * 显示名称
     *
     * @var string
     */
    protected string $displayName = '';

    /**
     * 描述
     *
     * @var string
     */
    protected string $description = '';

    /**
     * 工作流类型
     *
     * @var string
     */
    protected string $type = self::TYPE_BUSINESS;

    /**
     * 状态
     *
     * @var string
     */
    protected string $status = self::STATUS_DRAFT;

    /**
     * 版本号
     *
     * @var int
     */
    protected int $version = 1;

    /**
     * 租户ID
     *
     * @var int|null
     */
    protected ?int $tenantId = null;

    /**
     * 节点集合
     *
     * @var array<string, NodeInterface>
     */
    protected array $nodes = [];

    /**
     * 连接集合
     *
     * @var array<string, WorkflowConnectionInterface>
     */
    protected array $connections = [];

    /**
     * 元数据
     *
     * @var array
     */
    protected array $metadata = [];

    /**
     * 配置
     *
     * @var array
     */
    protected array $config = [];

    /**
     * 创建时间
     *
     * @var string|null
     */
    protected ?string $createdAt = null;

    /**
     * 更新时间
     *
     * @var string|null
     */
    protected ?string $updatedAt = null;

    /**
     * 发布时间
     *
     * @var string|null
     */
    protected ?string $publishedAt = null;

    /**
     * 创建者ID
     *
     * @var int|null
     */
    protected ?int $createdBy = null;

    /**
     * 更新者ID
     *
     * @var int|null
     */
    protected ?int $updatedBy = null;

    /**
     * 节点注册器
     *
     * @var NodeRegistry|null
     */
    protected ?NodeRegistry $nodeRegistry = null;

    /**
     * 构造函数
     *
     * @param string $name 工作流名称
     * @param array $config 配置选项
     */
    public function __construct(string $name, array $config = [])
    {
        $this->name = $name;
        $this->id = $config['id'] ?? $this->generateId();
        $this->displayName = $config['display_name'] ?? $config['displayName'] ?? $name;
        $this->description = $config['description'] ?? '';
        $this->type = $config['type'] ?? self::TYPE_BUSINESS;
        $this->status = $config['status'] ?? self::STATUS_DRAFT;
        $this->version = $config['version'] ?? 1;
        $this->tenantId = $config['tenant_id'] ?? $config['tenantId'] ?? null;
        $this->metadata = $config['metadata'] ?? [];
        $this->config = $config['config'] ?? [];
        $this->createdAt = $config['created_at'] ?? date('Y-m-d H:i:s');
        $this->updatedAt = $config['updated_at'] ?? null;
        $this->publishedAt = $config['published_at'] ?? null;
        $this->createdBy = $config['created_by'] ?? null;
        $this->updatedBy = $config['updated_by'] ?? null;
    }

    /**
     * 生成工作流ID
     *
     * @return string
     */
    protected function generateId(): string
    {
        return 'wf_' . substr(md5(uniqid((string)mt_rand(), true)), 0, 16);
    }

    /**
     * 设置节点注册器
     *
     * @param NodeRegistry $registry 节点注册器
     * @return static
     */
    public function setNodeRegistry(NodeRegistry $registry): static
    {
        $this->nodeRegistry = $registry;
        return $this;
    }

    /**
     * 获取节点注册器
     *
     * @return NodeRegistry
     */
    public function getNodeRegistry(): NodeRegistry
    {
        if ($this->nodeRegistry === null) {
            $this->nodeRegistry = new NodeRegistry(true);
        }
        return $this->nodeRegistry;
    }

    /**
     * {@inheritDoc}
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * 设置ID
     *
     * @param string $id ID
     * @return static
     */
    public function setId(string $id): static
    {
        $this->id = $id;
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * 设置名称
     *
     * @param string $name 名称
     * @return static
     */
    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getDisplayName(): string
    {
        return $this->displayName ?: $this->name;
    }

    /**
     * 设置显示名称
     *
     * @param string $displayName 显示名称
     * @return static
     */
    public function setDisplayName(string $displayName): static
    {
        $this->displayName = $displayName;
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * 设置描述
     *
     * @param string $description 描述
     * @return static
     */
    public function setDescription(string $description): static
    {
        $this->description = $description;
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * 设置类型
     *
     * @param string $type 类型
     * @return static
     */
    public function setType(string $type): static
    {
        $this->type = $type;
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * 设置状态
     *
     * @param string $status 状态
     * @return static
     */
    public function setStatus(string $status): static
    {
        $this->status = $status;
        $this->updatedAt = date('Y-m-d H:i:s');
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getVersion(): int
    {
        return $this->version;
    }

    /**
     * 设置版本号
     *
     * @param int $version 版本号
     * @return static
     */
    public function setVersion(int $version): static
    {
        $this->version = $version;
        return $this;
    }

    /**
     * 增加版本号
     *
     * @return static
     */
    public function incrementVersion(): static
    {
        $this->version++;
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getTenantId(): ?int
    {
        return $this->tenantId;
    }

    /**
     * 设置租户ID
     *
     * @param int|null $tenantId 租户ID
     * @return static
     */
    public function setTenantId(?int $tenantId): static
    {
        $this->tenantId = $tenantId;
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function addNode(NodeInterface $node): static
    {
        $this->nodes[$node->getId()] = $node;
        $this->updatedAt = date('Y-m-d H:i:s');
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function removeNode(string $nodeId): static
    {
        unset($this->nodes[$nodeId]);

        // 同时移除相关连接
        $this->connections = array_filter(
            $this->connections,
            fn(WorkflowConnectionInterface $conn) => $conn->getSourceNodeId() !== $nodeId && $conn->getTargetNodeId() !== $nodeId
        );

        $this->updatedAt = date('Y-m-d H:i:s');
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getNode(string $nodeId): ?NodeInterface
    {
        return $this->nodes[$nodeId] ?? null;
    }

    /**
     * 根据名称获取节点
     *
     * @param string $name 节点名称
     * @return NodeInterface|null
     */
    public function getNodeByName(string $name): ?NodeInterface
    {
        foreach ($this->nodes as $node) {
            if ($node->getName() === $name) {
                return $node;
            }
        }
        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function getNodes(): array
    {
        return $this->nodes;
    }

    /**
     * 根据类型获取节点
     *
     * @param string $type 节点类型
     * @return array<NodeInterface>
     */
    public function getNodesByType(string $type): array
    {
        return array_filter(
            $this->nodes,
            fn(NodeInterface $node) => $node->getType() === $type
        );
    }

    /**
     * {@inheritDoc}
     */
    public function hasNode(string $nodeId): bool
    {
        return isset($this->nodes[$nodeId]);
    }

    /**
     * {@inheritDoc}
     */
    public function addConnection(WorkflowConnectionInterface $connection): static
    {
        $this->connections[$connection->getId()] = $connection;
        $this->updatedAt = date('Y-m-d H:i:s');
        return $this;
    }

    /**
     * 连接两个节点
     *
     * @param string $sourceNodeId 源节点ID
     * @param string $targetNodeId 目标节点ID
     * @param array $config 连接配置
     * @return static
     */
    public function connect(string $sourceNodeId, string $targetNodeId, array $config = []): static
    {
        $connection = new WorkflowConnection($sourceNodeId, $targetNodeId, $config);
        return $this->addConnection($connection);
    }

    /**
     * {@inheritDoc}
     */
    public function removeConnection(string $connectionId): static
    {
        unset($this->connections[$connectionId]);
        $this->updatedAt = date('Y-m-d H:i:s');
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getConnection(string $connectionId): ?WorkflowConnectionInterface
    {
        return $this->connections[$connectionId] ?? null;
    }

    /**
     * {@inheritDoc}
     */
    public function getConnections(): array
    {
        return $this->connections;
    }

    /**
     * {@inheritDoc}
     */
    public function getStartNode(): ?NodeInterface
    {
        foreach ($this->nodes as $node) {
            if ($node->getType() === NodeInterface::TYPE_START) {
                return $node;
            }
        }
        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function getEndNodes(): array
    {
        return array_values(array_filter(
            $this->nodes,
            fn(NodeInterface $node) => $node->getType() === NodeInterface::TYPE_END
        ));
    }

    /**
     * {@inheritDoc}
     */
    public function getNodeOutputConnections(string $nodeId): array
    {
        return array_values(array_filter(
            $this->connections,
            fn(WorkflowConnectionInterface $conn) => $conn->getSourceNodeId() === $nodeId
        ));
    }

    /**
     * {@inheritDoc}
     */
    public function getNodeInputConnections(string $nodeId): array
    {
        return array_values(array_filter(
            $this->connections,
            fn(WorkflowConnectionInterface $conn) => $conn->getTargetNodeId() === $nodeId
        ));
    }

    /**
     * {@inheritDoc}
     */
    public function getNextNodes(string $nodeId): array
    {
        $outputConnections = $this->getNodeOutputConnections($nodeId);
        $nextNodes = [];

        foreach ($outputConnections as $connection) {
            $targetNode = $this->getNode($connection->getTargetNodeId());
            if ($targetNode !== null) {
                $nextNodes[] = $targetNode;
            }
        }

        return $nextNodes;
    }

    /**
     * {@inheritDoc}
     */
    public function getPreviousNodes(string $nodeId): array
    {
        $inputConnections = $this->getNodeInputConnections($nodeId);
        $previousNodes = [];

        foreach ($inputConnections as $connection) {
            $sourceNode = $this->getNode($connection->getSourceNodeId());
            if ($sourceNode !== null) {
                $previousNodes[] = $sourceNode;
            }
        }

        return $previousNodes;
    }

    /**
     * 获取任务节点
     *
     * @return array<NodeInterface>
     */
    public function getTaskNodes(): array
    {
        return array_values($this->getNodesByType(NodeInterface::TYPE_TASK));
    }

    /**
     * 获取网关节点
     *
     * @return array<NodeInterface>
     */
    public function getGatewayNodes(): array
    {
        return array_values($this->getNodesByType(NodeInterface::TYPE_GATEWAY));
    }

    /**
     * {@inheritDoc}
     */
    public function validate(): array
    {
        $errors = [];

        // 验证是否有开始节点
        $startNode = $this->getStartNode();
        if ($startNode === null) {
            $errors[] = [
                'code' => 'MISSING_START_NODE',
                'message' => '工作流必须有一个开始节点',
            ];
        }

        // 验证是否有结束节点
        $endNodes = $this->getEndNodes();
        if (empty($endNodes)) {
            $errors[] = [
                'code' => 'MISSING_END_NODE',
                'message' => '工作流必须至少有一个结束节点',
            ];
        }

        // 验证开始节点是否只有一个
        $startNodes = $this->getNodesByType(NodeInterface::TYPE_START);
        if (count($startNodes) > 1) {
            $errors[] = [
                'code' => 'MULTIPLE_START_NODES',
                'message' => '工作流只能有一个开始节点',
            ];
        }

        // 验证所有节点都有连接（除了结束节点）
        foreach ($this->nodes as $node) {
            $nodeId = $node->getId();
            $type = $node->getType();

            // 开始节点不需要输入连接
            if ($type !== NodeInterface::TYPE_START) {
                $inputConnections = $this->getNodeInputConnections($nodeId);
                if (empty($inputConnections)) {
                    $errors[] = [
                        'code' => 'NO_INPUT_CONNECTION',
                        'message' => "节点 '{$node->getName()}' ({$nodeId}) 没有输入连接",
                        'node_id' => $nodeId,
                    ];
                }
            }

            // 结束节点不需要输出连接
            if ($type !== NodeInterface::TYPE_END) {
                $outputConnections = $this->getNodeOutputConnections($nodeId);
                if (empty($outputConnections)) {
                    $errors[] = [
                        'code' => 'NO_OUTPUT_CONNECTION',
                        'message' => "节点 '{$node->getName()}' ({$nodeId}) 没有输出连接",
                        'node_id' => $nodeId,
                    ];
                }
            }
        }

        // 验证连接的节点是否存在
        foreach ($this->connections as $connection) {
            $sourceNodeId = $connection->getSourceNodeId();
            $targetNodeId = $connection->getTargetNodeId();

            if (!$this->hasNode($sourceNodeId)) {
                $errors[] = [
                    'code' => 'INVALID_SOURCE_NODE',
                    'message' => "连接 '{$connection->getId()}' 的源节点 '{$sourceNodeId}' 不存在",
                    'connection_id' => $connection->getId(),
                ];
            }

            if (!$this->hasNode($targetNodeId)) {
                $errors[] = [
                    'code' => 'INVALID_TARGET_NODE',
                    'message' => "连接 '{$connection->getId()}' 的目标节点 '{$targetNodeId}' 不存在",
                    'connection_id' => $connection->getId(),
                ];
            }
        }

        // 验证是否存在从开始节点到结束节点的路径
        if ($startNode !== null && !empty($endNodes)) {
            $reachable = $this->findReachableNodes($startNode->getId());
            foreach ($endNodes as $endNode) {
                if (!in_array($endNode->getId(), $reachable, true)) {
                    $errors[] = [
                        'code' => 'UNREACHABLE_END_NODE',
                        'message' => "结束节点 '{$endNode->getName()}' 无法从开始节点到达",
                        'node_id' => $endNode->getId(),
                    ];
                }
            }
        }

        return $errors;
    }

    /**
     * 查找从指定节点可达的所有节点
     *
     * @param string $startNodeId 起始节点ID
     * @return array<string> 可达节点ID列表
     */
    protected function findReachableNodes(string $startNodeId): array
    {
        $reachable = [];
        $queue = [$startNodeId];

        while (!empty($queue)) {
            $nodeId = array_shift($queue);
            if (in_array($nodeId, $reachable, true)) {
                continue;
            }
            $reachable[] = $nodeId;

            $nextNodes = $this->getNextNodes($nodeId);
            foreach ($nextNodes as $nextNode) {
                if (!in_array($nextNode->getId(), $reachable, true)) {
                    $queue[] = $nextNode->getId();
                }
            }
        }

        return $reachable;
    }

    /**
     * {@inheritDoc}
     */
    public function isValid(): bool
    {
        return empty($this->validate());
    }

    /**
     * {@inheritDoc}
     */
    public function publish(): static
    {
        $errors = $this->validate();
        if (!empty($errors)) {
            throw new \InvalidArgumentException('工作流验证失败: ' . json_encode($errors, JSON_UNESCAPED_UNICODE));
        }

        $this->status = self::STATUS_PUBLISHED;
        $this->publishedAt = date('Y-m-d H:i:s');
        $this->updatedAt = date('Y-m-d H:i:s');
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function archive(): static
    {
        $this->status = self::STATUS_ARCHIVED;
        $this->updatedAt = date('Y-m-d H:i:s');
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function disable(): static
    {
        $this->status = self::STATUS_DISABLED;
        $this->updatedAt = date('Y-m-d H:i:s');
        return $this;
    }

    /**
     * 创建新版本
     *
     * @return static
     */
    public function createNewVersion(): static
    {
        $newDefinition = $this->clone();
        $newDefinition->incrementVersion();
        $newDefinition->setStatus(self::STATUS_DRAFT);
        $newDefinition->publishedAt = null;
        $newDefinition->createdAt = date('Y-m-d H:i:s');
        $newDefinition->updatedAt = null;
        return $newDefinition;
    }

    /**
     * {@inheritDoc}
     */
    public function clone(?string $newId = null): static
    {
        $clone = new static($this->name, [
            'id' => $newId ?? $this->generateId(),
            'display_name' => $this->displayName,
            'description' => $this->description,
            'type' => $this->type,
            'status' => $this->status,
            'version' => $this->version,
            'tenant_id' => $this->tenantId,
            'metadata' => $this->metadata,
            'config' => $this->config,
        ]);

        // 克隆节点
        foreach ($this->nodes as $node) {
            $clone->addNode($node);
        }

        // 克隆连接
        foreach ($this->connections as $connection) {
            $clone->addConnection(WorkflowConnection::fromArray($connection->toArray()));
        }

        return $clone;
    }

    /**
     * 获取元数据
     *
     * @return array
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * 设置元数据
     *
     * @param array $metadata 元数据
     * @return static
     */
    public function setMetadata(array $metadata): static
    {
        $this->metadata = $metadata;
        return $this;
    }

    /**
     * 获取指定键的元数据
     *
     * @param string $key 键
     * @param mixed $default 默认值
     * @return mixed
     */
    public function getMeta(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * 设置指定键的元数据
     *
     * @param string $key 键
     * @param mixed $value 值
     * @return static
     */
    public function setMeta(string $key, mixed $value): static
    {
        $this->metadata[$key] = $value;
        return $this;
    }

    /**
     * 获取配置
     *
     * @return array
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * 设置配置
     *
     * @param array $config 配置
     * @return static
     */
    public function setConfig(array $config): static
    {
        $this->config = $config;
        return $this;
    }

    /**
     * 获取创建时间
     *
     * @return string|null
     */
    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    /**
     * 获取更新时间
     *
     * @return string|null
     */
    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    /**
     * 获取发布时间
     *
     * @return string|null
     */
    public function getPublishedAt(): ?string
    {
        return $this->publishedAt;
    }

    /**
     * 检查是否为草稿
     *
     * @return bool
     */
    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /**
     * 检查是否已发布
     *
     * @return bool
     */
    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /**
     * 检查是否已归档
     *
     * @return bool
     */
    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }

    /**
     * 检查是否已禁用
     *
     * @return bool
     */
    public function isDisabled(): bool
    {
        return $this->status === self::STATUS_DISABLED;
    }

    /**
     * {@inheritDoc}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'display_name' => $this->displayName,
            'description' => $this->description,
            'type' => $this->type,
            'status' => $this->status,
            'version' => $this->version,
            'tenant_id' => $this->tenantId,
            'nodes' => array_map(fn(NodeInterface $node) => $node->toArray(), $this->nodes),
            'connections' => array_map(
                fn(WorkflowConnectionInterface $conn) => $conn->toArray(),
                $this->connections
            ),
            'metadata' => $this->metadata,
            'config' => $this->config,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'published_at' => $this->publishedAt,
            'created_by' => $this->createdBy,
            'updated_by' => $this->updatedBy,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public static function fromArray(array $data): static
    {
        $definition = new static($data['name'] ?? '', [
            'id' => $data['id'] ?? null,
            'display_name' => $data['display_name'] ?? $data['displayName'] ?? null,
            'description' => $data['description'] ?? '',
            'type' => $data['type'] ?? self::TYPE_BUSINESS,
            'status' => $data['status'] ?? self::STATUS_DRAFT,
            'version' => $data['version'] ?? 1,
            'tenant_id' => $data['tenant_id'] ?? $data['tenantId'] ?? null,
            'metadata' => $data['metadata'] ?? [],
            'config' => $data['config'] ?? [],
            'created_at' => $data['created_at'] ?? null,
            'updated_at' => $data['updated_at'] ?? null,
            'published_at' => $data['published_at'] ?? null,
            'created_by' => $data['created_by'] ?? null,
            'updated_by' => $data['updated_by'] ?? null,
        ]);

        // 加载节点
        if (!empty($data['nodes'])) {
            $registry = $definition->getNodeRegistry();
            foreach ($data['nodes'] as $nodeData) {
                try {
                    $node = $registry->createFromArray($nodeData);
                    $definition->addNode($node);
                } catch (\Exception $e) {
                    // 记录错误但继续处理
                }
            }
        }

        // 加载连接
        if (!empty($data['connections'])) {
            foreach ($data['connections'] as $connData) {
                $connection = WorkflowConnection::fromArray($connData);
                $definition->addConnection($connection);
            }
        }

        return $definition;
    }

    /**
     * {@inheritDoc}
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * {@inheritDoc}
     */
    public static function fromJson(string $json): static
    {
        $data = json_decode($json, true);
        if ($data === null) {
            throw new \InvalidArgumentException('无效的JSON格式');
        }
        return static::fromArray($data);
    }
}
