<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Discipline;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Forum\Moderation\ModerationReasonCode;
use InvalidArgumentException;

final readonly class DisciplineAction
{
    public DateTimeImmutable $startsAt;
    public ?DateTimeImmutable $expiresAt;
    public ?DateTimeImmutable $revokedAt;

    /** @param list<DisciplineRestrictionKey> $restrictions */
    public function __construct(
        public EntityId $actionId,
        public EntityId $userId,
        public ?EntityId $actorUserId,
        public DisciplineActionType $type,
        public ModerationReasonCode $reasonCode,
        public string $reasonText,
        public int $points,
        public ?string $warningDefinitionKey,
        public array $restrictions,
        public bool $appealable,
        DateTimeImmutable $startsAt,
        ?DateTimeImmutable $expiresAt = null,
        ?DateTimeImmutable $revokedAt = null,
        public ?EntityId $revokedByUserId = null,
        public ?string $revokeReason = null,
    ) {
        if (preg_match('/^[a-f0-9]{32}$/D', $this->actionId->value()) !== 1) {
            throw new InvalidArgumentException('Discipline action id must be a 128-bit lowercase hexadecimal identifier.');
        }
        UserId::assert($this->userId);
        if ($this->actorUserId !== null) UserId::assert($this->actorUserId);
        if ($this->revokedByUserId !== null) UserId::assert($this->revokedByUserId);
        if (strlen($this->reasonText) > 2000) {
            throw new InvalidArgumentException('Discipline reason text cannot exceed 2000 bytes.');
        }
        if ($this->points < 0 || $this->points > 1000) {
            throw new InvalidArgumentException('Discipline points are outside the supported range.');
        }
        if ($this->warningDefinitionKey !== null
            && preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $this->warningDefinitionKey) !== 1
        ) {
            throw new InvalidArgumentException('Discipline warning definition key is invalid.');
        }
        if ($this->type === DisciplineActionType::Warning && $this->warningDefinitionKey === null) {
            throw new InvalidArgumentException('Warning actions require a warning definition.');
        }
        if ($this->type !== DisciplineActionType::Warning && $this->points !== 0) {
            throw new InvalidArgumentException('Only warning actions may carry warning points.');
        }

        $seen = [];
        foreach ($this->restrictions as $restriction) {
            if (!$restriction instanceof DisciplineRestrictionKey) {
                throw new InvalidArgumentException('Discipline restrictions must be typed restriction keys.');
            }
            if (isset($seen[$restriction->value])) {
                throw new InvalidArgumentException('Discipline restrictions must be unique.');
            }
            $seen[$restriction->value] = true;
        }
        if ($this->type === DisciplineActionType::Restriction && $this->restrictions === []) {
            throw new InvalidArgumentException('Restriction actions require at least one restriction key.');
        }
        if ($this->type !== DisciplineActionType::Restriction && $this->restrictions !== []) {
            throw new InvalidArgumentException('Only restriction actions may carry restriction keys.');
        }

        if ($this->type === DisciplineActionType::Suspension && $expiresAt === null) {
            throw new InvalidArgumentException('Suspension actions require an expiry timestamp.');
        }

        $utc = new DateTimeZone('UTC');
        $this->startsAt = $startsAt->setTimezone($utc);
        $this->expiresAt = $expiresAt?->setTimezone($utc);
        $this->revokedAt = $revokedAt?->setTimezone($utc);
        if ($this->expiresAt !== null && $this->expiresAt <= $this->startsAt) {
            throw new InvalidArgumentException('Discipline expiry must be later than its start time.');
        }
        if ($this->revokedAt !== null && $this->revokedAt < $this->startsAt) {
            throw new InvalidArgumentException('Discipline revocation cannot predate the action.');
        }
        if ($this->revokedAt === null && ($this->revokedByUserId !== null || $this->revokeReason !== null)) {
            throw new InvalidArgumentException('Discipline revocation metadata requires a revocation timestamp.');
        }
        if ($this->revokeReason !== null && strlen($this->revokeReason) > 1000) {
            throw new InvalidArgumentException('Discipline revoke reason cannot exceed 1000 bytes.');
        }
    }

    public function isActiveAt(DateTimeImmutable $at): bool
    {
        $at = $at->setTimezone(new DateTimeZone('UTC'));
        return $this->revokedAt === null
            && $this->startsAt <= $at
            && ($this->expiresAt === null || $this->expiresAt > $at);
    }

    public function isPermanent(): bool
    {
        return $this->expiresAt === null;
    }

    public function appealReference(): ?string
    {
        return $this->appealable ? 'discipline:' . $this->actionId->value() : null;
    }

    public function statusAt(DateTimeImmutable $at): string
    {
        if ($this->revokedAt !== null) return 'revoked';
        $at = $at->setTimezone(new DateTimeZone('UTC'));
        if ($this->expiresAt !== null && $this->expiresAt <= $at) return 'expired';
        return $this->startsAt <= $at ? 'active' : 'scheduled';
    }
}
