<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Abuse;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class AbuseEvent
{
    public DateTimeImmutable $occurredAt;
    public ?DateTimeImmutable $resolvedAt;

    /** @param list<string> $matchedRuleKeys */
    public function __construct(
        public EntityId $eventId,
        public AbuseEventType $eventType,
        public AbuseAction $decision,
        public array $matchedRuleKeys,
        public ?EntityId $actorUserId,
        public ?string $targetType,
        public ?EntityId $targetId,
        public ?string $identityFingerprint,
        public ?string $ipFingerprint,
        public ?string $deviceFingerprint,
        public ?string $contentFingerprint,
        DateTimeImmutable $occurredAt,
        ?DateTimeImmutable $resolvedAt = null,
        public ?EntityId $resolvedByUserId = null,
        public ?string $resolution = null,
    ) {
        if (preg_match('/^[a-f0-9]{32}$/D', $this->eventId->value()) !== 1) {
            throw new InvalidArgumentException('Abuse event id must be a 128-bit lowercase hexadecimal identifier.');
        }
        if ($this->decision === AbuseAction::Allow) {
            throw new InvalidArgumentException('Allow decisions are not persisted as abuse events.');
        }
        if ($this->matchedRuleKeys === []) {
            throw new InvalidArgumentException('Abuse event requires at least one matched rule.');
        }
        foreach ($this->matchedRuleKeys as $key) {
            if (!is_string($key) || preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $key) !== 1) {
                throw new InvalidArgumentException('Abuse event matched rule key is invalid.');
            }
        }
        if ($this->actorUserId !== null) UserId::assert($this->actorUserId);
        if ($this->resolvedByUserId !== null) UserId::assert($this->resolvedByUserId);
        if (($this->targetType === null) !== ($this->targetId === null)) {
            throw new InvalidArgumentException('Abuse event target type and id must be set together.');
        }
        if ($this->targetType !== null && preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $this->targetType) !== 1) {
            throw new InvalidArgumentException('Abuse event target type is invalid.');
        }
        foreach ([$this->identityFingerprint,$this->ipFingerprint,$this->deviceFingerprint,$this->contentFingerprint] as $fingerprint) {
            if ($fingerprint !== null && preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
                throw new InvalidArgumentException('Abuse event fingerprint is invalid.');
            }
        }
        if ($this->resolution !== null && (trim($this->resolution) === '' || strlen($this->resolution) > 64)) {
            throw new InvalidArgumentException('Abuse event resolution is invalid.');
        }
        $utc = new DateTimeZone('UTC');
        $this->occurredAt = $occurredAt->setTimezone($utc);
        $this->resolvedAt = $resolvedAt?->setTimezone($utc);
        if ($this->resolvedAt === null && ($this->resolvedByUserId !== null || $this->resolution !== null)) {
            throw new InvalidArgumentException('Abuse resolution metadata requires a resolution timestamp.');
        }
    }

    public function isResolved(): bool
    {
        return $this->resolvedAt !== null;
    }
}
