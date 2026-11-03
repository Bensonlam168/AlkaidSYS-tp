<?php

declare(strict_types=1);

namespace Domain\Permission\Model;

/**
 * Role Model | 角色模型
 *
 * @package Domain\Permission\Model
 */
class Role
{
    protected ?int $id = null;
    protected int $tenantId;
    protected string $name;
    protected string $slug;
    protected ?string $description = null;
    protected bool $isSystem = false;
    protected array $permissions = [];

    public function __construct(int $tenantId, string $name, string $slug)
    {
        $this->tenantId = $tenantId;
        $this->name = $name;
        $this->slug = $slug;
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
    public function getName(): string
    {
        return $this->name;
    }
    public function getSlug(): string
    {
        return $this->slug;
    }

    /**
     * 获取角色所属租户 ID
     * Get the tenant ID this role belongs to
     *
     * 仓储的更新/删除必须用它拼 `where('tenant_id', ...)`：
     * 缺了它就只能按 id 定位，等于允许跨租户改写他人角色。
     */
    public function getTenantId(): int
    {
        return $this->tenantId;
    }
    public function isSystem(): bool
    {
        return $this->isSystem;
    }

    public function assignPermission(int $permissionId): self
    {
        if (!in_array($permissionId, $this->permissions)) {
            $this->permissions[] = $permissionId;
        }
        return $this;
    }

    public function hasPermission(int $permissionId): bool
    {
        return in_array($permissionId, $this->permissions);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenantId,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'is_system' => $this->isSystem,
        ];
    }

    public function fromArray(array $data): self
    {
        if (isset($data['id'])) {
            $this->id = (int)$data['id'];
        }
        if (isset($data['description'])) {
            $this->description = $data['description'];
        }
        if (isset($data['is_system'])) {
            $this->isSystem = (bool)$data['is_system'];
        }
        return $this;
    }
}
