<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Definition;

use Alkaid\Workflow\Engine\Definition\Interfaces\WorkflowDefinitionInterface;

/**
 * 工作流版本管理
 *
 * 管理工作流定义的版本历史，支持版本追踪、回滚等功能。
 *
 * @package Alkaid\Workflow\Engine\Definition
 */
class WorkflowVersion
{
    /**
     * 版本ID
     *
     * @var string
     */
    protected string $id;

    /**
     * 工作流定义ID
     *
     * @var string
     */
    protected string $workflowId;

    /**
     * 版本号
     *
     * @var int
     */
    protected int $version;

    /**
     * 版本标签
     *
     * @var string|null
     */
    protected ?string $tag = null;

    /**
     * 版本说明
     *
     * @var string
     */
    protected string $description = '';

    /**
     * 版本状态
     *
     * @var string
     */
    protected string $status;

    /**
     * 工作流定义快照
     *
     * @var array
     */
    protected array $snapshot = [];

    /**
     * 变更记录
     *
     * @var array
     */
    protected array $changelog = [];

    /**
     * 租户ID
     *
     * @var int|null
     */
    protected ?int $tenantId = null;

    /**
     * 创建时间
     *
     * @var string
     */
    protected string $createdAt;

    /**
     * 创建者ID
     *
     * @var int|null
     */
    protected ?int $createdBy = null;

    /**
     * 发布时间
     *
     * @var string|null
     */
    protected ?string $publishedAt = null;

    /**
     * 归档时间
     *
     * @var string|null
     */
    protected ?string $archivedAt = null;

    /**
     * 构造函数
     *
     * @param string $workflowId 工作流定义ID
     * @param int $version 版本号
     * @param array $config 配置选项
     */
    public function __construct(string $workflowId, int $version, array $config = [])
    {
        $this->workflowId = $workflowId;
        $this->version = $version;
        $this->id = $config['id'] ?? $this->generateId();
        $this->tag = $config['tag'] ?? null;
        $this->description = $config['description'] ?? '';
        $this->status = $config['status'] ?? WorkflowDefinitionInterface::STATUS_DRAFT;
        $this->snapshot = $config['snapshot'] ?? [];
        $this->changelog = $config['changelog'] ?? [];
        $this->tenantId = $config['tenant_id'] ?? null;
        $this->createdAt = $config['created_at'] ?? date('Y-m-d H:i:s');
        $this->createdBy = $config['created_by'] ?? null;
        $this->publishedAt = $config['published_at'] ?? null;
        $this->archivedAt = $config['archived_at'] ?? null;
    }

    /**
     * 生成版本ID
     *
     * @return string
     */
    protected function generateId(): string
    {
        return 'ver_' . substr(md5(uniqid((string)mt_rand(), true)), 0, 16);
    }

    /**
     * 从工作流定义创建版本
     *
     * @param WorkflowDefinitionInterface $definition 工作流定义
     * @param string|null $description 版本说明
     * @param string|null $tag 版本标签
     * @return static
     */
    public static function fromDefinition(
        WorkflowDefinitionInterface $definition,
        ?string $description = null,
        ?string $tag = null
    ): static {
        $version = new static($definition->getId(), $definition->getVersion(), [
            'tag' => $tag,
            'description' => $description ?? '',
            'status' => $definition->getStatus(),
            'snapshot' => $definition->toArray(),
            'tenant_id' => $definition->getTenantId(),
        ]);

        return $version;
    }

    /**
     * 获取版本ID
     *
     * @return string
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * 获取工作流定义ID
     *
     * @return string
     */
    public function getWorkflowId(): string
    {
        return $this->workflowId;
    }

    /**
     * 获取版本号
     *
     * @return int
     */
    public function getVersion(): int
    {
        return $this->version;
    }

    /**
     * 获取版本标签
     *
     * @return string|null
     */
    public function getTag(): ?string
    {
        return $this->tag;
    }

    /**
     * 设置版本标签
     *
     * @param string|null $tag 标签
     * @return static
     */
    public function setTag(?string $tag): static
    {
        $this->tag = $tag;
        return $this;
    }

    /**
     * 获取版本说明
     *
     * @return string
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * 设置版本说明
     *
     * @param string $description 说明
     * @return static
     */
    public function setDescription(string $description): static
    {
        $this->description = $description;
        return $this;
    }

    /**
     * 获取版本状态
     *
     * @return string
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * 设置版本状态
     *
     * @param string $status 状态
     * @return static
     */
    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    /**
     * 获取工作流定义快照
     *
     * @return array
     */
    public function getSnapshot(): array
    {
        return $this->snapshot;
    }

    /**
     * 设置工作流定义快照
     *
     * @param array $snapshot 快照
     * @return static
     */
    public function setSnapshot(array $snapshot): static
    {
        $this->snapshot = $snapshot;
        return $this;
    }

    /**
     * 从快照恢复工作流定义
     *
     * @return WorkflowDefinition
     */
    public function restoreDefinition(): WorkflowDefinition
    {
        return WorkflowDefinition::fromArray($this->snapshot);
    }

    /**
     * 获取变更记录
     *
     * @return array
     */
    public function getChangelog(): array
    {
        return $this->changelog;
    }

    /**
     * 添加变更记录
     *
     * @param string $type 变更类型
     * @param string $description 变更描述
     * @param array $data 变更数据
     * @return static
     */
    public function addChange(string $type, string $description, array $data = []): static
    {
        $this->changelog[] = [
            'type' => $type,
            'description' => $description,
            'data' => $data,
            'timestamp' => date('Y-m-d H:i:s'),
        ];
        return $this;
    }

    /**
     * 获取租户ID
     *
     * @return int|null
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
     * 获取创建时间
     *
     * @return string
     */
    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }

    /**
     * 获取创建者ID
     *
     * @return int|null
     */
    public function getCreatedBy(): ?int
    {
        return $this->createdBy;
    }

    /**
     * 设置创建者ID
     *
     * @param int|null $createdBy 创建者ID
     * @return static
     */
    public function setCreatedBy(?int $createdBy): static
    {
        $this->createdBy = $createdBy;
        return $this;
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
     * 获取归档时间
     *
     * @return string|null
     */
    public function getArchivedAt(): ?string
    {
        return $this->archivedAt;
    }

    /**
     * 发布版本
     *
     * @return static
     */
    public function publish(): static
    {
        $this->status = WorkflowDefinitionInterface::STATUS_PUBLISHED;
        $this->publishedAt = date('Y-m-d H:i:s');
        return $this;
    }

    /**
     * 归档版本
     *
     * @return static
     */
    public function archive(): static
    {
        $this->status = WorkflowDefinitionInterface::STATUS_ARCHIVED;
        $this->archivedAt = date('Y-m-d H:i:s');
        return $this;
    }

    /**
     * 检查是否为草稿
     *
     * @return bool
     */
    public function isDraft(): bool
    {
        return $this->status === WorkflowDefinitionInterface::STATUS_DRAFT;
    }

    /**
     * 检查是否已发布
     *
     * @return bool
     */
    public function isPublished(): bool
    {
        return $this->status === WorkflowDefinitionInterface::STATUS_PUBLISHED;
    }

    /**
     * 检查是否已归档
     *
     * @return bool
     */
    public function isArchived(): bool
    {
        return $this->status === WorkflowDefinitionInterface::STATUS_ARCHIVED;
    }

    /**
     * 获取版本字符串
     *
     * @return string
     */
    public function getVersionString(): string
    {
        $versionStr = 'v' . $this->version;
        if ($this->tag !== null) {
            $versionStr .= '-' . $this->tag;
        }
        return $versionStr;
    }

    /**
     * 转换为数组
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'workflow_id' => $this->workflowId,
            'version' => $this->version,
            'tag' => $this->tag,
            'description' => $this->description,
            'status' => $this->status,
            'snapshot' => $this->snapshot,
            'changelog' => $this->changelog,
            'tenant_id' => $this->tenantId,
            'created_at' => $this->createdAt,
            'created_by' => $this->createdBy,
            'published_at' => $this->publishedAt,
            'archived_at' => $this->archivedAt,
        ];
    }

    /**
     * 从数组创建
     *
     * @param array $data 数据数组
     * @return static
     */
    public static function fromArray(array $data): static
    {
        return new static(
            $data['workflow_id'] ?? $data['workflowId'] ?? '',
            $data['version'] ?? 1,
            [
                'id' => $data['id'] ?? null,
                'tag' => $data['tag'] ?? null,
                'description' => $data['description'] ?? '',
                'status' => $data['status'] ?? WorkflowDefinitionInterface::STATUS_DRAFT,
                'snapshot' => $data['snapshot'] ?? [],
                'changelog' => $data['changelog'] ?? [],
                'tenant_id' => $data['tenant_id'] ?? $data['tenantId'] ?? null,
                'created_at' => $data['created_at'] ?? null,
                'created_by' => $data['created_by'] ?? null,
                'published_at' => $data['published_at'] ?? null,
                'archived_at' => $data['archived_at'] ?? null,
            ]
        );
    }
}
