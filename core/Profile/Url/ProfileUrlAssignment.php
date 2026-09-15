<?php

declare(strict_types=1);

namespace Forwext\Core\Profile\Url;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;

final readonly class ProfileUrlAssignment
{
    public function __construct(
        public EntityId $userId,
        public ProfileSlug $slug,
        public DateTimeImmutable $changedAt,
        public DateTimeImmutable $windowStartedAt,
        public int $changesInWindow,
    ) {
        UserId::assert($userId);
        if ($changedAt->getTimezone()->getName() !== 'UTC' || $windowStartedAt->getTimezone()->getName() !== 'UTC') {
            throw new ProfileUrlException('Custom profile URL timestamps must use UTC.');
        }
        if ($changesInWindow < 0) {
            throw new ProfileUrlException('Custom profile URL change counter cannot be negative.');
        }
    }

    public static function utc(DateTimeImmutable $time): DateTimeImmutable
    {
        return $time->setTimezone(new DateTimeZone('UTC'));
    }
}
