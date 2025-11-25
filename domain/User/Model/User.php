<?php

declare(strict_types=1);

namespace Domain\User\Model;

use Domain\User\Interfaces\UserInterface;

/**
 * User Model | 用户模型
 *
 * Represents a user in the system.
 * 表示系统中的用户。
 *
 * @package Domain\User\Model
 */
class User implements UserInterface
{
    protected ?int $id = null;
    protected int $tenantId;
    protected string $username;
    protected string $email;
    protected string $password;
    protected ?string $name = null;
    protected ?string $avatar = null;
    protected ?string $phone = null;
    protected string $status = 'active';
    protected ?string $emailVerifiedAt = null;
    protected ?string $lastLoginAt = null;
    protected ?string $lastLoginIp = null;
    protected ?string $createdAt = null;
    protected ?string $updatedAt = null;
    protected array $roles = [];

    /**
     * Constructor | 构造函数
     */
    public function __construct(int $tenantId, string $username, string $email, string $password)
    {
        $this->tenantId = $tenantId;
        $this->username = $username;
        $this->email = $email;
        $this->password = $password;
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

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        if (!in_array($status, ['active', 'inactive', 'locked'])) {
            throw new \InvalidArgumentException("Invalid status: {$status}");
        }
        $this->status = $status;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function activate(): self
    {
        $this->status = 'active';
        return $this;
    }

    public function deactivate(): self
    {
        $this->status = 'inactive';
        return $this;
    }

    public function lock(): self
    {
        $this->status = 'locked';
        return $this;
    }

    public function verifyPassword(string $password): bool
    {
        return password_verify($password, $this->password);
    }

    public function updatePassword(string $newPassword): self
    {
        $this->password = password_hash($newPassword, PASSWORD_DEFAULT);
        return $this;
    }

    public function recordLogin(string $ip): self
    {
        $this->lastLoginAt = date('Y-m-d H:i:s');
        $this->lastLoginIp = $ip;
        return $this;
    }

    public function assignRole(int $roleId): self
    {
        if (!in_array($roleId, $this->roles)) {
            $this->roles[] = $roleId;
        }
        return $this;
    }

    public function removeRole(int $roleId): self
    {
        $this->roles = array_filter($this->roles, fn ($id) => $id !== $roleId);
        return $this;
    }

    public function getRoles(): array
    {
        return $this->roles;
    }

    public function hasRole(int $roleId): bool
    {
        return in_array($roleId, $this->roles);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenantId,
            'username' => $this->username,
            'email' => $this->email,
            'name' => $this->name,
            'avatar' => $this->avatar,
            'phone' => $this->phone,
            'status' => $this->status,
            'email_verified_at' => $this->emailVerifiedAt,
            'last_login_at' => $this->lastLoginAt,
            'last_login_ip' => $this->lastLoginIp,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    public function fromArray(array $data): self
    {
        if (isset($data['id'])) {
            $this->id = (int)$data['id'];
        }
        if (isset($data['name'])) {
            $this->name = $data['name'];
        }
        if (isset($data['avatar'])) {
            $this->avatar = $data['avatar'];
        }
        if (isset($data['phone'])) {
            $this->phone = $data['phone'];
        }
        if (isset($data['status'])) {
            $this->status = $data['status'];
        }
        if (isset($data['email_verified_at'])) {
            $this->emailVerifiedAt = $data['email_verified_at'];
        }
        if (isset($data['last_login_at'])) {
            $this->lastLoginAt = $data['last_login_at'];
        }
        if (isset($data['last_login_ip'])) {
            $this->lastLoginIp = $data['last_login_ip'];
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
