<?php

declare(strict_types=1);

namespace Forwext\Core\Reward;

use Forwext\Core\Domain\Entity\EntityId;

interface RewardRepository
{
    public function lockUser(EntityId $userId): bool;

    public function definitionByKey(string $rewardKey): ?RewardDefinition;

    public function definition(EntityId $rewardId): ?RewardDefinition;

    /** @return list<RewardDefinition> */
    public function definitions(int $limit = 500): array;

    public function saveDefinition(RewardDefinition $definition): void;

    public function grantByRequest(RewardGrantRequest $request): ?RewardGrant;

    public function grant(EntityId $grantId): ?RewardGrant;

    /** @return list<RewardGrant> */
    public function grantsBySource(string $sourceType, string $sourceId, EntityId $recipientUserId): array;

    /** @return list<RewardGrant> */
    public function retryable(int $limit = 100): array;

    public function saveGrant(RewardGrant $grant): void;

    public function activeEntitlementCount(EntityId $userId, string $providerKey, EntityId $targetId): int;

    public function assignmentManaged(EntityId $userId, string $providerKey, EntityId $targetId): bool;

    public function setAssignmentManaged(EntityId $userId, string $providerKey, EntityId $targetId, bool $managed): void;

    /** @return list<RewardBinding> */
    public function bindings(string $sourceType, EntityId $sourceDefinitionId, bool $activeOnly = true): array;

    /** @return list<RewardBinding> */
    public function allBindings(int $limit = 500): array;

    public function saveBinding(RewardBinding $binding): void;
}
