<?php

declare(strict_types=1);

namespace Domain\Organization\Model;

/**
 * Menu Model | 菜单模型
 *
 * @package Domain\Organization\Model
 */
class Menu
{
    // Menu types
    public const TYPE_CATALOG = 'catalog';
    public const TYPE_MENU = 'menu';
    public const TYPE_EMBEDDED = 'embedded';
    public const TYPE_LINK = 'link';
    public const TYPE_BUTTON = 'button';

    public const VALID_TYPES = [
        self::TYPE_CATALOG,
        self::TYPE_MENU,
        self::TYPE_EMBEDDED,
        self::TYPE_LINK,
        self::TYPE_BUTTON,
    ];

    protected ?int $id = null;
    protected int $tenantId;
    protected ?int $pid = null;
    protected string $name;
    protected string $type = self::TYPE_MENU;
    protected ?string $path = null;
    protected ?string $component = null;
    protected ?string $redirect = null;
    protected ?string $authCode = null;
    protected ?array $meta = null;
    protected bool $status = true;
    protected int $sort = 0;
    protected ?string $createdAt = null;
    protected ?string $updatedAt = null;

    /** @var Menu[] */
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

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        if (!in_array($type, self::VALID_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid menu type: {$type}");
        }
        $this->type = $type;
        return $this;
    }

    public function getPath(): ?string
    {
        return $this->path;
    }

    public function setPath(?string $path): self
    {
        $this->path = $path;
        return $this;
    }

    public function getComponent(): ?string
    {
        return $this->component;
    }

    public function setComponent(?string $component): self
    {
        $this->component = $component;
        return $this;
    }

    public function getRedirect(): ?string
    {
        return $this->redirect;
    }

    public function setRedirect(?string $redirect): self
    {
        $this->redirect = $redirect;
        return $this;
    }

    public function getAuthCode(): ?string
    {
        return $this->authCode;
    }

    public function setAuthCode(?string $authCode): self
    {
        $this->authCode = $authCode;
        return $this;
    }

    public function getMeta(): ?array
    {
        return $this->meta;
    }

    public function setMeta(?array $meta): self
    {
        $this->meta = $meta;
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

    /**
     * @return Menu[]
     */
    public function getChildren(): array
    {
        return $this->children;
    }

    /**
     * @param Menu[] $children
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
            'type' => $this->type,
            'path' => $this->path,
            'component' => $this->component,
            'redirect' => $this->redirect,
            'auth_code' => $this->authCode,
            'meta' => $this->meta,
            'status' => $this->status ? 1 : 0,
            'sort' => $this->sort,
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
        if (isset($data['type'])) {
            $this->type = $data['type'];
        }
        if (isset($data['path'])) {
            $this->path = $data['path'];
        }
        if (isset($data['component'])) {
            $this->component = $data['component'];
        }
        if (isset($data['redirect'])) {
            $this->redirect = $data['redirect'];
        }
        if (isset($data['auth_code'])) {
            $this->authCode = $data['auth_code'];
        }
        if (isset($data['meta'])) {
            $this->meta = is_array($data['meta']) ? $data['meta'] : json_decode($data['meta'], true);
        }
        if (isset($data['status'])) {
            $this->status = (bool)$data['status'];
        }
        if (isset($data['sort'])) {
            $this->sort = (int)$data['sort'];
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
