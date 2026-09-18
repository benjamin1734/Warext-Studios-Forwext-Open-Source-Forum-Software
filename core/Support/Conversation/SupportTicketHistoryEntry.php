<?php

declare(strict_types=1);

namespace Forwext\Core\Support\Conversation;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class SupportTicketHistoryEntry
{
    public DateTimeImmutable $createdAt;

    /**
     * @param array<string,scalar|null> $payload
     */
    public function __construct(
        public EntityId $historyId,
        public EntityId $ticketId,
        public ?EntityId $actorUserId,
        public SupportHistoryEventType $eventType,
        public SupportHistoryVisibility $visibility,
        public array $payload,
        DateTimeImmutable $createdAt,
    ) {
        if ($this->actorUserId !== null) {
            UserId::assert($this->actorUserId);
        }
        foreach ($this->payload as $key => $value) {
            if (!is_string($key)
                || preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,63}$/D', $key) !== 1
                || (!is_scalar($value) && $value !== null)
            ) {
                throw new InvalidArgumentException('Support ticket history payload is invalid.');
            }
        }
        $this->createdAt = $createdAt->setTimezone(new DateTimeZone('UTC'));
    }

    public static function generateId(): EntityId
    {
        return EntityId::fromString(bin2hex(random_bytes(16)));
    }
}
