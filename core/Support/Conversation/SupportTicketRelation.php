<?php

declare(strict_types=1);

namespace Forwext\Core\Support\Conversation;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class SupportTicketRelation
{
    public DateTimeImmutable $createdAt;

    public function __construct(
        public EntityId $relationId,
        public SupportTicketRelationType $type,
        public EntityId $sourceTicketId,
        public EntityId $targetTicketId,
        public ?EntityId $createdByUserId,
        DateTimeImmutable $createdAt,
    ) {
        if ($this->sourceTicketId->equals($this->targetTicketId)) {
            throw new InvalidArgumentException('Support ticket relation cannot point to the same ticket.');
        }
        if ($this->createdByUserId !== null) {
            UserId::assert($this->createdByUserId);
        }
        $this->createdAt = $createdAt->setTimezone(new DateTimeZone('UTC'));
    }

    public static function generateId(): EntityId
    {
        return EntityId::fromString(bin2hex(random_bytes(16)));
    }
}
