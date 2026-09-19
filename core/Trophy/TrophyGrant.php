<?php

declare(strict_types=1);

namespace Forwext\Core\Trophy;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class TrophyGrant
{
    public DateTimeImmutable $awardedAt;
    public ?DateTimeImmutable $revokedAt;

    public function __construct(
        public EntityId $grantId,
        public EntityId $trophyId,
        public EntityId $userId,
        public string $source,
        public ?EntityId $awardedByUserId,
        DateTimeImmutable $awardedAt,
        public ?EntityId $revokedByUserId = null,
        ?DateTimeImmutable $revokedAt = null,
        public ?string $reason = null,
    ) {
        foreach ([$this->grantId, $this->trophyId] as $id) {
            if (preg_match('/^[a-f0-9]{32}$/D', $id->value()) !== 1) {
                throw new InvalidArgumentException('Trophy grant identifier is invalid.');
            }
        }
        UserId::assert($this->userId);
        if ($this->awardedByUserId !== null) UserId::assert($this->awardedByUserId);
        if ($this->revokedByUserId !== null) UserId::assert($this->revokedByUserId);
        if (preg_match('/^[a-z][a-z0-9_.-]{2,63}$/D', $this->source) !== 1) {
            throw new InvalidArgumentException('Trophy grant source is invalid.');
        }
        if ($this->reason !== null && (trim($this->reason) === '' || strlen($this->reason) > 500 || preg_match('//u', $this->reason) !== 1)) {
            throw new InvalidArgumentException('Trophy grant reason is invalid.');
        }

        $utc = new DateTimeZone('UTC');
        $this->awardedAt = $awardedAt->setTimezone($utc);
        $this->revokedAt = $revokedAt?->setTimezone($utc);
        if ($this->revokedAt !== null && $this->revokedAt < $this->awardedAt) {
            throw new InvalidArgumentException('Trophy grant revoke timestamp is invalid.');
        }
    }

    public static function generateId(): EntityId
    {
        return EntityId::fromString(bin2hex(random_bytes(16)));
    }

    public function active(): bool
    {
        return $this->revokedAt === null;
    }
}
