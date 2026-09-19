<?php

declare(strict_types=1);

namespace Forwext\Core\Giveaway;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class GiveawayEntry
{
    public DateTimeImmutable $enteredAt;

    public function __construct(
        public EntityId $entryId,
        public EntityId $giveawayId,
        public EntityId $userId,
        public int $entryCount,
        public string $networkFingerprint,
        public ?string $deviceFingerprint,
        DateTimeImmutable $enteredAt,
    ) {
        if (preg_match('/^[a-f0-9]{32}$/D', $this->entryId->value()) !== 1
            || preg_match('/^[a-f0-9]{32}$/D', $this->giveawayId->value()) !== 1
        ) {
            throw new InvalidArgumentException('Giveaway entry id is invalid.');
        }
        UserId::assert($this->userId);
        if ($this->entryCount < 1 || $this->entryCount > 1000) {
            throw new InvalidArgumentException('Giveaway entry count is invalid.');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $this->networkFingerprint) !== 1
            || ($this->deviceFingerprint !== null
                && preg_match('/^[a-f0-9]{64}$/D', $this->deviceFingerprint) !== 1)
        ) {
            throw new InvalidArgumentException('Giveaway entry fingerprint is invalid.');
        }
        $this->enteredAt = $enteredAt->setTimezone(new DateTimeZone('UTC'));
    }

    public static function generateId(): EntityId
    {
        return EntityId::fromString(bin2hex(random_bytes(16)));
    }
}
