<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Layout\Builder;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class LayoutRevision
{
    public DateTimeImmutable $createdAt;

    public function __construct(
        public EntityId $revisionId,
        public EntityId $layoutId,
        public LayoutDocument $document,
        public LayoutRevisionSource $source,
        public EntityId $createdBy,
        DateTimeImmutable $createdAt,
    ) {
        foreach ([$this->revisionId, $this->layoutId, $this->createdBy] as $id) {
            if (preg_match('/^[a-f0-9]{32}$/D', $id->value()) !== 1) {
                throw new InvalidArgumentException('Layout revision identifiers must be 128-bit lowercase hexadecimal values.');
            }
        }

        $this->createdAt = $createdAt->setTimezone(new DateTimeZone('UTC'));
    }

    public static function generateId(): EntityId
    {
        return EntityId::fromString(bin2hex(random_bytes(16)));
    }
}
