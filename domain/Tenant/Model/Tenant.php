<?php

declare(strict_types=1);

namespace Domain\Tenant\Model;

use Domain\Tenant\Interfaces\TenantInterface;

/**
 * Tenant Model | 租户模型
 *
 * Represents a tenant in the multi-tenant system.
 * 表示多租户系统中的租户。
 *
 * @package Domain\Tenant\Model
 */
class Tenant implements TenantInterface
{
    protected ?int $id = null;
    protected string $name;
    protected string $slug;
    protected ?string $domain = null;
    protected string $status = 'active';
    protected array $config = [];
    protected int $maxSites = 1;
    protected int $maxUsers = 10;
    protected ?string $expiresAt = null;
    protected ?string $createdAt = null;
    protected ?string $updatedAt = null;

    /**
     * Constructor | 构造函数
     */
    public function __construct(string $name, string $slug, array $config = [])
    {
        $this->name = $name;
        $this->slug = $slug;
        $this->config = $config;
    }

    /**
     * {@inheritdoc}
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Set ID | 设置ID
     */
    public function setId(int $id): self
    {
        $this->id = $id;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * {@inheritdoc}
     */
    public function getSlug(): string
    {
        return $this->slug;
    }

    /**
     * {@inheritdoc}
     */
    public function getDomain(): ?string
    {
        return $this->domain;
    }

    /**
     * Set domain | 设置域名
     */
    public function setDomain(?string $domain): self
    {
        $this->domain = $domain;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * Set status | 设置状态
     */
    public function setStatus(string $status): self
    {
        if (!in_array($status, ['active', 'inactive', 'suspended'])) {
            throw new \InvalidArgumentException("Invalid status: {$status}");
        }
        $this->status = $status;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * {@inheritdoc}
     */
    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    /**
     * Activate tenant | 激活租户
     */
    public function activate(): self
    {
        $this->status = 'active';
        return $this;
    }

    /**
     * Suspend tenant | 暂停租户
     */
    public function suspend(): self
    {
        $this->status = 'suspended';
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Set config | 设置配置
     */
    public function setConfig(array $config): self
    {
        $this->config = $config;
        return $this;
    }

    /**
     * Get max sites | 获取最大站点数
     */
    public function getMaxSites(): int
    {
        return $this->maxSites;
    }

    /**
     * Set max sites | 设置最大站点数
     */
    public function setMaxSites(int $maxSites): self
    {
        $this->maxSites = $maxSites;
        return $this;
    }

    /**
     * Get max users | 获取最大用户数
     */
    public function getMaxUsers(): int
    {
        return $this->maxUsers;
    }

    /**
     * Set max users | 设置最大用户数
     */
    public function setMaxUsers(int $maxUsers): self
    {
        $this->maxUsers = $maxUsers;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'domain' => $this->domain,
            'status' => $this->status,
            'config' => $this->config,
            'max_sites' => $this->maxSites,
            'max_users' => $this->maxUsers,
            'expires_at' => $this->expiresAt,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function fromArray(array $data): self
    {
        if (isset($data['id'])) {
            $this->id = (int)$data['id'];
        }
        if (isset($data['domain'])) {
            $this->domain = $data['domain'];
        }
        if (isset($data['status'])) {
            $this->status = $data['status'];
        }
        if (isset($data['config'])) {
            $this->config = is_array($data['config'])
                ? $data['config']
                : json_decode($data['config'], true);
        }
        if (isset($data['max_sites'])) {
            $this->maxSites = (int)$data['max_sites'];
        }
        if (isset($data['max_users'])) {
            $this->maxUsers = (int)$data['max_users'];
        }
        if (isset($data['expires_at'])) {
            $this->expiresAt = $data['expires_at'];
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
