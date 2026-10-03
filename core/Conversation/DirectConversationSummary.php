<?php

declare(strict_types=1);

namespace Forwext\Core\Conversation;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class DirectConversationSummary
{
    public function __construct(
        public EntityId $conversationId,
        public EntityId $otherUserId,
        public string $otherUsername,
        public string $lastMessageBody,
        public DateTimeImmutable $updatedAt,
        public int $unreadCount,
    ) {
        UserId::assert($otherUserId);
        if (trim($otherUsername) === '') {
            throw new InvalidArgumentException('Direct conversation counterpart username cannot be empty.');
        }
        if ($unreadCount < 0) {
            throw new InvalidArgumentException('Direct conversation unread count cannot be negative.');
        }
    }
}
