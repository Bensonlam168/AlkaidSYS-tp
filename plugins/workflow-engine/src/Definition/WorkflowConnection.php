<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Definition;

use Alkaid\Workflow\Engine\Definition\Interfaces\WorkflowConnectionInterface;

/**
 * 工作流连接/边
 *
 * 表示工作流中节点之间的连接关系。
 *
 * @package Alkaid\Workflow\Engine\Definition
 */
class WorkflowConnection implements WorkflowConnectionInterface
{
    /**
     * 连接ID
     *
     * @var string
     */
    protected string $id;

    /**
     * 连接名称
     *
     * @var string
     */
    protected string $name = '';

    /**
     * 连接类型
     *
     * @var string
     */
    protected string $type = self::TYPE_SEQUENCE;

    /**
     * 源节点ID
     *
     * @var string
     */
    protected string $sourceNodeId;

    /**
     * 目标节点ID
     *
     * @var string
     */
    protected string $targetNodeId;

    /**
     * 条件表达式
     *
     * @var string|null
     */
    protected ?string $condition = null;

    /**
     * 优先级
     *
     * @var int
     */
    protected int $priority = 0;

    /**
     * 是否为默认连接
     *
     * @var bool
     */
    protected bool $default = false;

    /**
     * 标签文本
     *
     * @var string|null
     */
    protected ?string $label = null;

    /**
     * 元数据
     *
     * @var array
     */
    protected array $metadata = [];

    /**
     * 构造函数
     *
     * @param string $sourceNodeId 源节点ID
     * @param string $targetNodeId 目标节点ID
     * @param array $config 配置选项
     */
    public function __construct(string $sourceNodeId, string $targetNodeId, array $config = [])
    {
        $this->sourceNodeId = $sourceNodeId;
        $this->targetNodeId = $targetNodeId;
        $this->id = $config['id'] ?? $this->generateId();
        $this->name = $config['name'] ?? '';
        $this->type = $config['type'] ?? self::TYPE_SEQUENCE;
        $this->condition = $config['condition'] ?? null;
        $this->priority = $config['priority'] ?? 0;
        $this->default = $config['default'] ?? false;
        $this->label = $config['label'] ?? null;
        $this->metadata = $config['metadata'] ?? [];

        // 如果有条件，自动设置类型为条件流
        if ($this->condition !== null && $this->type === self::TYPE_SEQUENCE) {
            $this->type = self::TYPE_CONDITIONAL;
        }

        // 如果是默认连接，设置类型为默认流
        if ($this->default && $this->type === self::TYPE_SEQUENCE) {
            $this->type = self::TYPE_DEFAULT;
        }
    }

    /**
     * 生成连接ID
     *
     * @return string
     */
    protected function generateId(): string
    {
        return 'conn_' . substr(md5(uniqid((string)mt_rand(), true)), 0, 12);
    }

    /**
     * {@inheritDoc}
     */
    public function getId(): string
    {
        return $this->id;
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
    public function getSourceNodeId(): string
    {
        return $this->sourceNodeId;
    }

    /**
     * {@inheritDoc}
     */
    public function getTargetNodeId(): string
    {
        return $this->targetNodeId;
    }

    /**
     * {@inheritDoc}
     */
    public function getCondition(): ?string
    {
        return $this->condition;
    }

    /**
     * {@inheritDoc}
     */
    public function setCondition(?string $condition): static
    {
        $this->condition = $condition;
        if ($condition !== null && $this->type === self::TYPE_SEQUENCE) {
            $this->type = self::TYPE_CONDITIONAL;
        }
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * {@inheritDoc}
     */
    public function setPriority(int $priority): static
    {
        $this->priority = $priority;
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function isDefault(): bool
    {
        return $this->default;
    }

    /**
     * {@inheritDoc}
     */
    public function setDefault(bool $default): static
    {
        $this->default = $default;
        if ($default && $this->type === self::TYPE_SEQUENCE) {
            $this->type = self::TYPE_DEFAULT;
        }
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getLabel(): ?string
    {
        return $this->label;
    }

    /**
     * {@inheritDoc}
     */
    public function setLabel(?string $label): static
    {
        $this->label = $label;
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * {@inheritDoc}
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
     * {@inheritDoc}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'source_node_id' => $this->sourceNodeId,
            'target_node_id' => $this->targetNodeId,
            'condition' => $this->condition,
            'priority' => $this->priority,
            'default' => $this->default,
            'label' => $this->label,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public static function fromArray(array $data): static
    {
        $sourceNodeId = $data['source_node_id'] ?? $data['sourceNodeId'] ?? '';
        $targetNodeId = $data['target_node_id'] ?? $data['targetNodeId'] ?? '';

        return new static($sourceNodeId, $targetNodeId, [
            'id' => $data['id'] ?? null,
            'name' => $data['name'] ?? '',
            'type' => $data['type'] ?? self::TYPE_SEQUENCE,
            'condition' => $data['condition'] ?? null,
            'priority' => $data['priority'] ?? 0,
            'default' => $data['default'] ?? false,
            'label' => $data['label'] ?? null,
            'metadata' => $data['metadata'] ?? [],
        ]);
    }

    /**
     * 创建顺序流连接
     *
     * @param string $sourceNodeId 源节点ID
     * @param string $targetNodeId 目标节点ID
     * @param string|null $label 标签
     * @return static
     */
    public static function sequence(string $sourceNodeId, string $targetNodeId, ?string $label = null): static
    {
        return new static($sourceNodeId, $targetNodeId, [
            'type' => self::TYPE_SEQUENCE,
            'label' => $label,
        ]);
    }

    /**
     * 创建条件流连接
     *
     * @param string $sourceNodeId 源节点ID
     * @param string $targetNodeId 目标节点ID
     * @param string $condition 条件表达式
     * @param string|null $label 标签
     * @return static
     */
    public static function conditional(
        string $sourceNodeId,
        string $targetNodeId,
        string $condition,
        ?string $label = null
    ): static {
        return new static($sourceNodeId, $targetNodeId, [
            'type' => self::TYPE_CONDITIONAL,
            'condition' => $condition,
            'label' => $label,
        ]);
    }

    /**
     * 创建默认流连接
     *
     * @param string $sourceNodeId 源节点ID
     * @param string $targetNodeId 目标节点ID
     * @param string|null $label 标签
     * @return static
     */
    public static function defaultFlow(string $sourceNodeId, string $targetNodeId, ?string $label = null): static
    {
        return new static($sourceNodeId, $targetNodeId, [
            'type' => self::TYPE_DEFAULT,
            'default' => true,
            'label' => $label ?? '默认',
        ]);
    }
}
