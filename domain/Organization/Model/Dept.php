<?php

declare(strict_types=1);

namespace Domain\Organization\Model;

/**
 * Dept Model | 部门模型
 *
 * @package Domain\Organization\Model
 */
class Dept
{
    protected ?int $id = null;
    protected int $tenantId;
    protected ?int $pid = null;
    protected string $name;
    protected bool $status = true;
    protected int $sort = 0;
    protected ?string $remark = null;
    protected ?string $createdAt = null;
    protected ?string $updatedAt = null;

    /** @var Dept[] */
    protected array $children = [];

    public function __construct(int $tenantId, string $name)
    {
        $this->tenantId = $tenantId;
        $this->name = $name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function getTenantId(): int
    {
        return $this->tenantId;
    }

    public function getPid(): ?int
    {
        return $this->pid;
    }

    public function setPid(?int $pid): self
    {
        $this->pid = $pid;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getStatus(): bool
    {
        return $this->status;
    }

    public function setStatus(bool $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getSort(): int
    {
        return $this->sort;
    }

    public function setSort(int $sort): self
    {
        $this->sort = $sort;
        return $this;
    }

    public function getRemark(): ?string
    {
        return $this->remark;
    }

    public function setRemark(?string $remark): self
    {
        $this->remark = $remark;
        return $this;
    }

    /**
     * @return Dept[]
     */
    public function getChildren(): array
    {
        return $this->children;
    }

    /**
     * @param Dept[] $children
     */
    public function setChildren(array $children): self
    {
        $this->children = $children;
        return $this;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenantId,
            'pid' => $this->pid,
            'name' => $this->name,
            'status' => $this->status ? 1 : 0,
            'sort' => $this->sort,
            'remark' => $this->remark,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    public function fromArray(array $data): self
    {
        if (isset($data['id'])) {
            $this->id = (int)$data['id'];
        }
        if (isset($data['pid'])) {
            $this->pid = $data['pid'] !== null ? (int)$data['pid'] : null;
        }
        if (isset($data['name'])) {
            $this->name = $data['name'];
        }
        if (isset($data['status'])) {
            $this->status = (bool)$data['status'];
        }
        if (isset($data['sort'])) {
            $this->sort = (int)$data['sort'];
        }
        if (isset($data['remark'])) {
            $this->remark = $data['remark'];
        }
        if (isset($data['created_at'])) {
            $this->createdAt = $data['created_at'];
        }
        if (isset($data['updated_at'])) {
            $this->updatedAt = $data['updated_at'];
        }
        return $this;
    }
}
