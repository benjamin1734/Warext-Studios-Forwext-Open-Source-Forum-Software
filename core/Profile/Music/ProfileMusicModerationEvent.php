<?php

declare(strict_types=1);

namespace Forwext\Core\Profile\Music;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Profile\ProfileException;

final readonly class ProfileMusicModerationEvent
{
    public function __construct(
        public EntityId $userId,
        public EntityId $actorId,
        public ProfileMusicModerationAction $action,
        public ?string $reasonCode,
        public DateTimeImmutable $occurredAt,
    ) {
        UserId::assert($userId);
        UserId::assert($actorId);
        if ($action === ProfileMusicModerationAction::Block && $reasonCode === null) {
            throw new ProfileException('Blocking profile music requires a reason code.');
        }
        if ($action === ProfileMusicModerationAction::Unblock && $reasonCode !== null) {
            throw new ProfileException('Unblocking profile music may not retain a block reason.');
        }
        if ($reasonCode !== null && preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $reasonCode) !== 1) {
            throw new ProfileException('Profile music moderation reason code is invalid.');
        }
        if ($occurredAt->getTimezone()->getName() !== 'UTC') {
            throw new ProfileException('Profile music moderation timestamps must use UTC.');
        }
    }

    public static function utc(DateTimeImmutable $time): DateTimeImmutable
    {
        return $time->setTimezone(new DateTimeZone('UTC'));
    }
}
