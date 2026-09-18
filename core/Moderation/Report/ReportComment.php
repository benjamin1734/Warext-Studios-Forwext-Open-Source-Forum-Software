<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Report;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class ReportComment
{
    public function __construct(
        public EntityId $commentId,
        public EntityId $groupId,
        public ?EntityId $moderatorUserId,
        public string $body,
        DateTimeImmutable $createdAt,
    ) {
        if (preg_match('/^[0-9a-f]{32}$/D', $this->commentId->value()) !== 1
            || preg_match('/^[0-9a-f]{32}$/D', $this->groupId->value()) !== 1
        ) {
            throw new InvalidArgumentException('Report comment identifier is invalid.');
        }
        if ($this->moderatorUserId !== null) {
            UserId::assert($this->moderatorUserId);
        }
        if (trim($this->body) === '' || strlen($this->body) > 4000) {
            throw new InvalidArgumentException('Report moderator comment must contain 1-4000 UTF-8 bytes.');
        }
        $this->createdAt = $createdAt->setTimezone(new DateTimeZone('UTC'));
    }

    public DateTimeImmutable $createdAt;
}
