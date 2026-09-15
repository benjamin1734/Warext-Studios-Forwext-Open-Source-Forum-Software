<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Metadata;

use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class ThreadPrefix
{
    public function __construct(
        private EntityId $id,
        private EntityId $groupId,
        private string $name,
        private int $sortOrder = 0,
        private bool $enabled = true,
    ) {
        MetadataId::assert($this->id);
        MetadataId::assert($this->groupId);
        $name = trim($this->name);
        if ($name === '' || strlen($name) > 100) {
            throw new InvalidArgumentException('Thread prefix name must contain 1-100 UTF-8 bytes.');
        }
        if ($this->sortOrder < 0 || $this->sortOrder > 65535) {
            throw new InvalidArgumentException('Thread prefix sort order must fit an unsigned 16-bit integer.');
        }
    }

    public function id(): EntityId
    {
        return $this->id;
    }

    public function groupId(): EntityId
    {
        return $this->groupId;
    }

    public function name(): string
    {
        return trim($this->name);
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
