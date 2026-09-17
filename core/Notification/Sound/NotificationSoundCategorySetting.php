<?php

declare(strict_types=1);

namespace Forwext\Core\Notification\Sound;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class NotificationSoundCategorySetting
{
    public function __construct(
        public EntityId $userId,
        public string $categoryKey,
        public bool $enabled,
        public ?string $soundKey,
        public DateTimeImmutable $updatedAt,
    ) {
        UserId::assert($userId);
        if (preg_match('/^[a-z][a-z0-9_.-]{1,95}$/D', $categoryKey) !== 1) {
            throw new InvalidArgumentException('Notification category key is invalid.');
        }
        if ($soundKey !== null && preg_match('/^[a-z][a-z0-9_-]{1,31}$/D', $soundKey) !== 1) {
            throw new InvalidArgumentException('Notification category sound key is invalid.');
        }
    }
}
