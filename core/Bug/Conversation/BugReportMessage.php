<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Conversation;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class BugReportMessage
{
    public DateTimeImmutable $createdAt;

    public function __construct(
        public EntityId $messageId,
        public EntityId $reportId,
        public ?EntityId $authorUserId,
        public BugReportMessageRole $authorRole,
        public string $body,
        DateTimeImmutable $createdAt,
    ) {
        if ($this->authorUserId !== null) {
            UserId::assert($this->authorUserId);
        }
        if (trim($this->body) === '' || strlen($this->body) > 10000) {
            throw new InvalidArgumentException('Bug report message must contain 1-10000 UTF-8 bytes.');
        }
        $this->createdAt = $createdAt->setTimezone(new DateTimeZone('UTC'));
    }

    public static function generateId(): EntityId
    {
        return EntityId::fromString(bin2hex(random_bytes(16)));
    }
}
