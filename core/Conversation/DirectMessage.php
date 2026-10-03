<?php

declare(strict_types=1);

namespace Forwext\Core\Conversation;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class DirectMessage
{
    public function __construct(
        public EntityId $messageId,
        public EntityId $conversationId,
        public ?EntityId $authorUserId,
        public string $authorUsername,
        public string $body,
        public DateTimeImmutable $createdAt,
    ) {
        if ($authorUserId !== null) {
            UserId::assert($authorUserId);
        }
        if (trim($authorUsername) === '') {
            throw new InvalidArgumentException('Direct message author label cannot be empty.');
        }
        if (trim($body) === '') {
            throw new InvalidArgumentException('Direct message body cannot be empty.');
        }
    }
}
