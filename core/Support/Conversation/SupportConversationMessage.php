<?php

declare(strict_types=1);

namespace Forwext\Core\Support\Conversation;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class SupportConversationMessage
{
    public DateTimeImmutable $createdAt;

    public function __construct(
        public EntityId $messageId,
        public EntityId $ticketId,
        public ?EntityId $authorUserId,
        public SupportMessageRole $authorRole,
        public SupportMessageVisibility $visibility,
        public string $body,
        public ?string $cannedResponseKeySnapshot,
        public ?string $cannedResponseTitleSnapshot,
        public ?EntityId $copiedFromMessageId,
        DateTimeImmutable $createdAt,
    ) {
        if ($this->authorUserId !== null) {
            UserId::assert($this->authorUserId);
        }
        $body = trim($this->body);
        if ($body === '' || strlen($body) > 10000) {
            throw new InvalidArgumentException('Support message must contain 1-10000 UTF-8 bytes.');
        }
        if ($this->visibility === SupportMessageVisibility::Internal
            && $this->authorRole !== SupportMessageRole::Staff
        ) {
            throw new InvalidArgumentException('Only staff messages may be internal.');
        }
        if (($this->cannedResponseKeySnapshot === null) !== ($this->cannedResponseTitleSnapshot === null)) {
            throw new InvalidArgumentException('Canned response snapshots must be provided together.');
        }
        if ($this->cannedResponseKeySnapshot !== null
            && preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $this->cannedResponseKeySnapshot) !== 1
        ) {
            throw new InvalidArgumentException('Canned response key snapshot is invalid.');
        }
        if ($this->cannedResponseTitleSnapshot !== null
            && (trim($this->cannedResponseTitleSnapshot) === '' || strlen($this->cannedResponseTitleSnapshot) > 120)
        ) {
            throw new InvalidArgumentException('Canned response title snapshot is invalid.');
        }
        $this->createdAt = $createdAt->setTimezone(new DateTimeZone('UTC'));
    }

    public static function generateId(): EntityId
    {
        return EntityId::fromString(bin2hex(random_bytes(16)));
    }
}
