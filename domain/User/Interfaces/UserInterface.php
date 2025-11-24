<?php

declare(strict_types=1);

namespace Domain\User\Interfaces;

/**
 * User Interface | 用户接口
 * 
 * @package Domain\User\Interfaces
 */
interface UserInterface
{
    public function getId(): ?int;
    public function getTenantId(): int;
    public function getUsername(): string;
    public function getEmail(): string;
    public function getName(): ?string;
    public function getStatus(): string;
    public function isActive(): bool;
    public function verifyPassword(string $password): bool;
    public function toArray(): array;
    public function fromArray(array $data): self;
}
