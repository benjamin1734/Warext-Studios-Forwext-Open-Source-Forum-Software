<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Poll;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

interface PollRepository
{
    public function find(EntityId $pollId): ?Poll;

    public function findByThread(EntityId $threadId): ?Poll;

    public function create(Poll $poll): void;

    /** @param list<EntityId> $optionIds */
    public function castVote(
        EntityId $pollId,
        EntityId $userId,
        array $optionIds,
        DateTimeImmutable $at,
    ): void;

    public function hasVoted(EntityId $pollId, EntityId $userId): bool;

    public function voterCount(EntityId $pollId): int;

    public function results(Poll $poll, DateTimeImmutable $at, bool $includeVoters): PollResults;

    public function close(EntityId $pollId, DateTimeImmutable $at): void;
}
