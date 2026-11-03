<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Definition;

use Alkaid\Workflow\Engine\Definition\Interfaces\WorkflowDefinitionInterface;
use Alkaid\Workflow\Engine\Nodes\Interfaces\NodeInterface;
use Alkaid\Workflow\Engine\Nodes\NodeRegistry;
use Alkaid\Workflow\Engine\Nodes\Types\StartNode;
use Alkaid\Workflow\Engine\Nodes\Types\EndNode;
use Alkaid\Workflow\Engine\Nodes\Types\TaskNode;
use Alkaid\Workflow\Engine\Nodes\Types\GatewayNode;

/**
 * 工作流构建器
 *
 * 提供流式API来构建工作流定义，简化工作流的创建过程。
 *
 * @package Alkaid\Workflow\Engine\Definition
 */
class WorkflowBuilder
{
    /**
     * 工作流定义
     *
     * @var WorkflowDefinition
     */
    protected WorkflowDefinition $definition;

    /**
     * 节点注册器
     *
     * @var NodeRegistry
     */
    protected NodeRegistry $nodeRegistry;

    /**
     * 验证器
     *
     * @var WorkflowValidator|null
     */
    protected ?WorkflowValidator $validator = null;

    /**
     * 当前节点ID（用于链式调用）
     *
     * @var string|null
     */
    protected ?string $currentNodeId = null;

    /**
     * 最后添加的节点ID
     *
     * @var string|null
     */
    protected ?string $lastNodeId = null;

    /**
     * 构造函数
     *
     * @param string $name 工作流名称
     * @param array $config 配置选项
     */
    public function __construct(string $name, array $config = [])
    {
        $this->definition = new WorkflowDefinition($name, $config);
        $this->nodeRegistry = $config['node_registry'] ?? new NodeRegistry(true);
        $this->definition->setNodeRegistry($this->nodeRegistry);
    }

    /**
     * 创建新的构建器实例
     *
     * @param string $name 工作流名称
     * @param array $config 配置选项
     * @return static
     */
    public static function create(string $name, array $config = []): static
    {
        return new static($name, $config);
    }

    /**
     * 设置工作流ID
     *
     * @param string $id ID
     * @return static
     */
    public function id(string $id): static
    {
        $this->definition->setId($id);
        return $this;
    }

    /**
     * 设置显示名称
     *
     * @param string $displayName 显示名称
     * @return static
     */
    public function displayName(string $displayName): static
    {
        $this->definition->setDisplayName($displayName);
        return $this;
    }

    /**
     * 设置描述
     *
     * @param string $description 描述
     * @return static
     */
    public function description(string $description): static
    {
        $this->definition->setDescription($description);
        return $this;
    }

    /**
     * 设置类型
     *
     * @param string $type 类型
     * @return static
     */
    public function type(string $type): static
    {
        $this->definition->setType($type);
        return $this;
    }

    /**
     * 设置为审批流
     *
     * @return static
     */
    public function asApproval(): static
    {
        return $this->type(WorkflowDefinitionInterface::TYPE_APPROVAL);
    }

    /**
     * 设置为自动化流
     *
     * @return static
     */
    public function asAutomation(): static
    {
        return $this->type(WorkflowDefinitionInterface::TYPE_AUTOMATION);
    }

    /**
     * 设置为业务流程
     *
     * @return static
     */
    public function asBusiness(): static
    {
        return $this->type(WorkflowDefinitionInterface::TYPE_BUSINESS);
    }

    /**
     * 设置为集成流
     *
     * @return static
     */
    public function asIntegration(): static
    {
        return $this->type(WorkflowDefinitionInterface::TYPE_INTEGRATION);
    }

    /**
     * 设置租户ID
     *
     * @param int|null $tenantId 租户ID
     * @return static
     */
    public function tenant(?int $tenantId): static
    {
        $this->definition->setTenantId($tenantId);
        return $this;
    }

    /**
     * 设置版本号
     *
     * @param int $version 版本号
     * @return static
     */
    public function version(int $version): static
    {
        $this->definition->setVersion($version);
        return $this;
    }

    /**
     * 设置元数据
     *
     * @param string $key 键
     * @param mixed $value 值
     * @return static
     */
    public function meta(string $key, mixed $value): static
    {
        $this->definition->setMeta($key, $value);
        return $this;
    }

    /**
     * 设置配置
     *
     * @param array $config 配置
     * @return static
     */
    public function config(array $config): static
    {
        $this->definition->setConfig($config);
        return $this;
    }

    /**
     * 添加开始节点
     *
     * @param string $name 节点名称
     * @param array $config 节点配置
     * @return static
     */
    public function start(string $name = 'start', array $config = []): static
    {
        // 确保 config 中有 name
        $config['name'] = $config['name'] ?? $name;
        $node = new StartNode(null, $config);
        $this->definition->addNode($node);
        $this->lastNodeId = $node->getId();
        $this->currentNodeId = $node->getId();
        return $this;
    }

    /**
     * 添加结束节点
     *
     * @param string $name 节点名称
     * @param array $config 节点配置
     * @return static
     */
    public function end(string $name = 'end', array $config = []): static
    {
        // 确保 config 中有 name
        $config['name'] = $config['name'] ?? $name;
        $node = new EndNode(null, $config);
        $this->definition->addNode($node);

        // 自动连接上一个节点
        if ($this->currentNodeId !== null) {
            $this->definition->connect($this->currentNodeId, $node->getId());
        }

        $this->lastNodeId = $node->getId();
        $this->currentNodeId = null;
        return $this;
    }

    /**
     * 添加任务节点
     *
     * @param string $name 节点名称
     * @param string $subType 子类型
     * @param array $config 节点配置
     * @return static
     */
    public function task(string $name, string $subType = 'user', array $config = []): static
    {
        // 确保 config 中有 name 和 sub_type
        $config['name'] = $config['name'] ?? $name;
        $config['sub_type'] = $subType;
        $node = new TaskNode(null, $config);
        $this->definition->addNode($node);

        // 自动连接上一个节点
        if ($this->currentNodeId !== null) {
            $this->definition->connect($this->currentNodeId, $node->getId());
        }

        $this->lastNodeId = $node->getId();
        $this->currentNodeId = $node->getId();
        return $this;
    }

    /**
     * 添加用户任务节点
     *
     * @param string $name 节点名称
     * @param array $config 节点配置
     * @return static
     */
    public function userTask(string $name, array $config = []): static
    {
        return $this->task($name, 'user', $config);
    }

    /**
     * 添加服务任务节点
     *
     * @param string $name 节点名称
     * @param array $config 节点配置
     * @return static
     */
    public function serviceTask(string $name, array $config = []): static
    {
        return $this->task($name, 'service', $config);
    }

    /**
     * 添加脚本任务节点
     *
     * @param string $name 节点名称
     * @param array $config 节点配置
     * @return static
     */
    public function scriptTask(string $name, array $config = []): static
    {
        return $this->task($name, 'script', $config);
    }

    /**
     * 添加网关节点
     *
     * @param string $name 节点名称
     * @param string $subType 子类型
     * @param array $config 节点配置
     * @return static
     */
    public function gateway(string $name, string $subType = 'exclusive', array $config = []): static
    {
        // 确保 config 中有 name 和 sub_type
        $config['name'] = $config['name'] ?? $name;
        $config['sub_type'] = $subType;
        $node = new GatewayNode(null, $config);
        $this->definition->addNode($node);

        // 自动连接上一个节点
        if ($this->currentNodeId !== null) {
            $this->definition->connect($this->currentNodeId, $node->getId());
        }

        $this->lastNodeId = $node->getId();
        $this->currentNodeId = $node->getId();
        return $this;
    }

    /**
     * 添加排他网关
     *
     * @param string $name 节点名称
     * @param array $config 节点配置
     * @return static
     */
    public function exclusiveGateway(string $name, array $config = []): static
    {
        return $this->gateway($name, 'exclusive', $config);
    }

    /**
     * 添加并行网关
     *
     * @param string $name 节点名称
     * @param array $config 节点配置
     * @return static
     */
    public function parallelGateway(string $name, array $config = []): static
    {
        return $this->gateway($name, 'parallel', $config);
    }

    /**
     * 添加包容网关
     *
     * @param string $name 节点名称
     * @param array $config 节点配置
     * @return static
     */
    public function inclusiveGateway(string $name, array $config = []): static
    {
        return $this->gateway($name, 'inclusive', $config);
    }

    /**
     * 添加自定义节点
     *
     * @param NodeInterface $node 节点实例
     * @param bool $autoConnect 是否自动连接上一个节点
     * @return static
     */
    public function addNode(NodeInterface $node, bool $autoConnect = true): static
    {
        $this->definition->addNode($node);

        // 自动连接上一个节点
        if ($autoConnect && $this->currentNodeId !== null && $node->getType() !== NodeInterface::TYPE_START) {
            $this->definition->connect($this->currentNodeId, $node->getId());
        }

        $this->lastNodeId = $node->getId();
        if ($node->getType() !== NodeInterface::TYPE_END) {
            $this->currentNodeId = $node->getId();
        }

        return $this;
    }

    /**
     * 连接两个节点
     *
     * @param string $sourceId 源节点ID
     * @param string $targetId 目标节点ID
     * @param array $config 连接配置
     * @return static
     */
    public function connect(string $sourceId, string $targetId, array $config = []): static
    {
        $this->definition->connect($sourceId, $targetId, $config);
        return $this;
    }

    /**
     * 从当前节点连接到指定节点
     *
     * @param string $targetId 目标节点ID
     * @param array $config 连接配置
     * @return static
     */
    public function then(string $targetId, array $config = []): static
    {
        if ($this->currentNodeId !== null) {
            $this->definition->connect($this->currentNodeId, $targetId, $config);
            $this->currentNodeId = $targetId;
        }
        return $this;
    }

    /**
     * 添加条件分支
     *
     * @param string $targetId 目标节点ID
     * @param string $condition 条件表达式
     * @param string|null $label 标签
     * @return static
     */
    public function branch(string $targetId, string $condition, ?string $label = null): static
    {
        if ($this->currentNodeId !== null) {
            $connection = WorkflowConnection::conditional(
                $this->currentNodeId,
                $targetId,
                $condition,
                $label
            );
            $this->definition->addConnection($connection);
        }
        return $this;
    }

    /**
     * 添加默认分支
     *
     * @param string $targetId 目标节点ID
     * @param string|null $label 标签
     * @return static
     */
    public function defaultBranch(string $targetId, ?string $label = null): static
    {
        if ($this->currentNodeId !== null) {
            $connection = WorkflowConnection::defaultFlow(
                $this->currentNodeId,
                $targetId,
                $label
            );
            $this->definition->addConnection($connection);
        }
        return $this;
    }

    /**
     * 跳转到指定节点（设置当前节点，不创建连接）
     *
     * @param string $nodeId 节点ID
     * @return static
     */
    public function at(string $nodeId): static
    {
        $this->currentNodeId = $nodeId;
        return $this;
    }

    /**
     * 按名称跳转到节点
     *
     * @param string $name 节点名称
     * @return static
     */
    public function atNode(string $name): static
    {
        $node = $this->definition->getNodeByName($name);
        if ($node !== null) {
            $this->currentNodeId = $node->getId();
        }
        return $this;
    }

    /**
     * 获取当前节点ID
     *
     * @return string|null
     */
    public function getCurrentNodeId(): ?string
    {
        return $this->currentNodeId;
    }

    /**
     * 获取最后添加的节点ID
     *
     * @return string|null
     */
    public function getLastNodeId(): ?string
    {
        return $this->lastNodeId;
    }

    /**
     * 获取节点ID（按名称）
     *
     * @param string $name 节点名称
     * @return string|null
     */
    public function getNodeId(string $name): ?string
    {
        $node = $this->definition->getNodeByName($name);
        return $node?->getId();
    }

    /**
     * 设置验证器
     *
     * @param WorkflowValidator $validator 验证器
     * @return static
     */
    public function setValidator(WorkflowValidator $validator): static
    {
        $this->validator = $validator;
        return $this;
    }

    /**
     * 验证工作流
     *
     * @return ValidationResult
     */
    public function validate(): ValidationResult
    {
        $validator = $this->validator ?? new WorkflowValidator();
        return $validator->validate($this->definition);
    }

    /**
     * 检查工作流是否有效
     *
     * @return bool
     */
    public function isValid(): bool
    {
        return $this->validate()->isValid();
    }

    /**
     * 构建工作流定义
     *
     * @param bool $validate 是否验证
     * @return WorkflowDefinition
     * @throws \InvalidArgumentException
     */
    public function build(bool $validate = true): WorkflowDefinition
    {
        if ($validate) {
            $result = $this->validate();
            $result->throwIfInvalid();
        }

        return $this->definition;
    }

    /**
     * 构建并发布工作流
     *
     * @return WorkflowDefinition
     * @throws \InvalidArgumentException
     */
    public function buildAndPublish(): WorkflowDefinition
    {
        $definition = $this->build(true);
        $definition->publish();
        return $definition;
    }

    /**
     * 获取工作流定义（不验证）
     *
     * @return WorkflowDefinition
     */
    public function getDefinition(): WorkflowDefinition
    {
        return $this->definition;
    }

    /**
     * 导出为数组
     *
     * @return array
     */
    public function toArray(): array
    {
        return $this->definition->toArray();
    }

    /**
     * 导出为JSON
     *
     * @return string
     */
    public function toJson(): string
    {
        return $this->definition->toJson();
    }
}
