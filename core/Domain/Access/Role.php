<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Access;

use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class Role
{
    public function __construct(
        private EntityId $id,
        private AccessIdentifier $key,
        private string $name,
        private RoleKind $kind,
        private bool $protected,
        private int $priority = 0,
    ) {
        $name = trim($this->name);
        if ($name === '' || strlen($name) > 100) {
            throw new InvalidArgumentException('Role name must contain 1-100 UTF-8 bytes.');
        }
        if ($this->priority < 0 || $this->priority > 65535) {
            throw new InvalidArgumentException('Role priority must be between 0 and 65535.');
        }
        if ($this->kind === RoleKind::System && !$this->protected) {
            throw new InvalidArgumentException('System roles must be protected from destructive management actions.');
        }
    }

    public function id(): EntityId
    {
        return $this->id;
    }

    public function key(): AccessIdentifier
    {
        return $this->key;
    }

    public function name(): string
    {
        return trim($this->name);
    }

    public function kind(): RoleKind
    {
        return $this->kind;
    }

    public function isStaff(): bool
    {
        return $this->kind === RoleKind::Staff;
    }

    public function isSystem(): bool
    {
        return $this->kind === RoleKind::System;
    }

    public function isProtected(): bool
    {
        return $this->protected;
    }

    public function priority(): int
    {
        return $this->priority;
    }
}
