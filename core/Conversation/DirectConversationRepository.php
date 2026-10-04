<?php

declare(strict_types=1);

namespace Forwext\Core\Conversation;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

interface DirectConversationRepository
{
    public function findPair(EntityId $leftUserId, EntityId $rightUserId): ?EntityId;

    public function create(
        EntityId $actorId,
        EntityId $targetUserId,
        string $body,
        DateTimeImmutable $at,
    ): EntityId;

    public function append(
        EntityId $actorId,
        EntityId $conversationId,
        string $body,
        DateTimeImmutable $at,
    ): DirectMessage;

    /** @return list<DirectConversationSummary> */
    public function summaries(EntityId $actorId, int $limit = 30, int $offset = 0, bool $starredOnly = false): array;

    public function view(EntityId $actorId, EntityId $conversationId, int $messageLimit = 200): ?DirectConversationView;

    public function otherParticipant(EntityId $actorId, EntityId $conversationId): ?EntityId;

    public function markRead(EntityId $actorId, EntityId $conversationId, DateTimeImmutable $at): void;

    public function setStarred(EntityId $actorId, EntityId $conversationId, bool $starred, DateTimeImmutable $at): bool;

    public function leave(EntityId $actorId, EntityId $conversationId, DateTimeImmutable $at): bool;

    public function reactivate(EntityId $actorId, EntityId $conversationId): bool;
}
