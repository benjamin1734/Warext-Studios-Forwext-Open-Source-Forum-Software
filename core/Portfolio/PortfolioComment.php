<?php

declare(strict_types=1);

namespace Forwext\Core\Portfolio;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class PortfolioComment
{
    public DateTimeImmutable $createdAt;

    public function __construct(
        public EntityId $commentId,
        public EntityId $projectId,
        public EntityId $authorUserId,
        public string $body,
        public PortfolioCommentState $state,
        DateTimeImmutable $createdAt,
    ) {
        UserId::assert($this->authorUserId);
        if (preg_match('/^[a-f0-9]{32}$/D', $this->projectId->value()) !== 1
            || preg_match('/^[a-f0-9]{32}$/D', $this->commentId->value()) !== 1
            || trim($this->body) === ''
            || strlen($this->body) > 10000
            || preg_match('//u', $this->body) !== 1
        ) {
            throw new InvalidArgumentException('Portfolio comment is invalid.');
        }
        $this->createdAt = $createdAt->setTimezone(new DateTimeZone('UTC'));
    }

    public static function generateId(): EntityId
    {
        return EntityId::fromString(bin2hex(random_bytes(16)));
    }
}
