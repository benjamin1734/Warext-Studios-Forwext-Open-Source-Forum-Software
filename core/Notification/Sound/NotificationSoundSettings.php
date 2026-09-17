<?php

declare(strict_types=1);

namespace Forwext\Core\Notification\Sound;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class NotificationSoundSettings
{
    public function __construct(
        public EntityId $userId,
        public bool $muted,
        public int $volume,
        public string $defaultSoundKey,
        public DateTimeImmutable $updatedAt,
    ) {
        UserId::assert($userId);
        if ($volume < 0 || $volume > 100) throw new InvalidArgumentException('Notification sound volume must be between 0 and 100.');
        if (preg_match('/^[a-z][a-z0-9_-]{1,31}$/D', $defaultSoundKey) !== 1) {
            throw new InvalidArgumentException('Notification sound key is invalid.');
        }
    }

    public static function defaults(EntityId $userId, ?DateTimeImmutable $now = null): self
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        return new self($userId, false, 65, 'soft', $now->setTimezone(new DateTimeZone('UTC')));
    }
}
