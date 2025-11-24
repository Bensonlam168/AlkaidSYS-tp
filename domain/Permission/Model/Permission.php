<?php

declare(strict_types=1);

namespace Domain\Permission\Model;

/**
 * Permission Model | 权限模型
 * 
 * @package Domain\Permission\Model
 */
class Permission
{
    protected ?int $id = null;
    protected string $name;
    protected string $slug;
    protected ?string $resource = null;
    protected ?string $action = null;

    public function __construct(string $name, string $slug)
    {
        $this->name = $name;
        $this->slug = $slug;
    }

    public function getId(): ?int { return $this->id; }
    public function setId(int $id): self { $this->id = $id; return $this; }
    public function getName(): string { return $this->name; }
    public function getSlug(): string { return $this->slug; }
    public function getResource(): ?string { return $this->resource; }
    public function getAction(): ?string { return $this->action; }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'resource' => $this->resource,
            'action' => $this->action,
        ];
    }

    public function fromArray(array $data): self
    {
        if (isset($data['id'])) $this->id = (int)$data['id'];
        if (isset($data['resource'])) $this->resource = $data['resource'];
        if (isset($data['action'])) $this->action = $data['action'];
        return $this;
    }
}
