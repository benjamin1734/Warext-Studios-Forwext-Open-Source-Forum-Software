<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Access;

use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class UserGroup
{
    public function __construct(
        private EntityId $id,
        private AccessIdentifier $key,
        private string $name,
        private bool $system = false,
        private int $sortOrder = 0,
    ) {
        $name = trim($this->name);
        if ($name === '' || strlen($name) > 100) {
            throw new InvalidArgumentException('User-group name must contain 1-100 UTF-8 bytes.');
        }
        if ($this->sortOrder < 0 || $this->sortOrder > 65535) {
            throw new InvalidArgumentException('User-group sort order must be between 0 and 65535.');
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

    public function isSystem(): bool
    {
        return $this->system;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }
}
