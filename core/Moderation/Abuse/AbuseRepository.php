<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Abuse;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

interface AbuseRepository
{
    /** @return list<AbuseRule> */
    public function rules(AbuseEventType $eventType): array;

    /** @return list<AbuseRule> */
    public function allRules(): array;

    public function rule(string $key): ?AbuseRule;

    public function saveRule(AbuseRule $rule, DateTimeImmutable $at): void;

    public function consume(AbuseRule $rule, string $fingerprint, DateTimeImmutable $at): int;

    public function insertEvent(AbuseEvent $event): void;

    public function event(EntityId $eventId): ?AbuseEvent;

    /** @return list<AbuseEvent> */
    public function unresolved(int $limit = 100): array;

    public function unresolvedCount(): int;

    public function resolve(EntityId $eventId, EntityId $actorUserId, string $resolution, DateTimeImmutable $at): void;
}
