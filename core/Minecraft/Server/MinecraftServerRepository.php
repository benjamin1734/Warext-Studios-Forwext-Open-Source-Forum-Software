<?php

declare(strict_types=1);

namespace Forwext\Core\Minecraft\Server;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

interface MinecraftServerRepository
{
    /** @return list<MinecraftServer> */
    public function publicDirectory(
        ?string $query = null,
        ?string $edition = null,
        int $limit = 30,
        int $offset = 0,
    ): array;

    public function publicById(EntityId $serverId): ?MinecraftServer;

    /** @param list<EntityId> $serverIds @return list<MinecraftServer> */
    public function publicByIds(array $serverIds): array;

    /** @return list<MinecraftServerSeason> */
    public function publicSeasons(?string $state = null, int $limit = 30, int $offset = 0): array;

    public function voteSummary(
        EntityId $serverId,
        ?EntityId $voterUserId,
        DateTimeImmutable $now,
    ): MinecraftServerVoteSummary;

    public function castVote(
        EntityId $serverId,
        EntityId $voterUserId,
        DateTimeImmutable $now,
    ): bool;

    /** @return list<MinecraftServerUpdate> */
    public function publicUpdates(EntityId $serverId, int $limit = 20, int $offset = 0): array;

    /** @return list<MinecraftServerUpdate> */
    public function managementUpdates(EntityId $serverId, int $limit = 100): array;

    public function createUpdate(MinecraftServerUpdate $update, ?EntityId $requiredOwnerUserId = null): void;

    public function setUpdateState(
        EntityId $serverId,
        EntityId $updateId,
        string $state,
        DateTimeImmutable $now,
        ?EntityId $requiredOwnerUserId = null,
    ): bool;

    public function statistics(EntityId $serverId, DateTimeImmutable $now): MinecraftServerStatistics;

    public function managementById(EntityId $serverId): ?MinecraftServer;

    /** @return list<MinecraftServer> */
    public function managementDirectory(?EntityId $actorUserId = null, int $limit = 100): array;

    /** @return list<MinecraftServerTeamMember> */
    public function team(EntityId $serverId): array;

    public function teamRole(EntityId $serverId, EntityId $userId): ?string;

    public function upsertTeamMember(
        MinecraftServerTeamMember $member,
        ?EntityId $expectedOwnerUserId,
    ): void;

    public function removeTeamMember(
        EntityId $serverId,
        EntityId $userId,
        ?EntityId $expectedOwnerUserId,
        EntityId $actorUserId,
        DateTimeImmutable $now,
    ): bool;

    public function voteIntegration(EntityId $serverId): ?MinecraftServerVoteIntegration;

    public function saveVoteIntegration(
        EntityId $serverId,
        bool $enabled,
        ?string $tokenHash,
        ?string $tokenPrefix,
        bool $replaceToken,
        ?EntityId $expectedOwnerUserId,
        EntityId $actorUserId,
        DateTimeImmutable $now,
    ): void;

    public function acceptsVoteIntegrationToken(EntityId $serverId, string $tokenHash): bool;

    /** @return list<MinecraftServerVoteFeedEntry> */
    public function voteFeed(EntityId $serverId, int $limit = 100): array;

    /** @return list<MinecraftServerClaim> */
    public function claims(?EntityId $claimantUserId = null, ?EntityId $serverId = null, int $limit = 100): array;

    public function createClaim(MinecraftServerClaim $claim): void;

    public function reviewClaim(
        EntityId $claimId,
        EntityId $reviewerUserId,
        bool $approve,
        ?string $reviewNote,
        DateTimeImmutable $now,
    ): void;

    public function updateDetails(
        EntityId $serverId,
        ?EntityId $expectedOwnerUserId,
        EntityId $actorUserId,
        string $name,
        string $summary,
        string $description,
        string $host,
        int $port,
        string $edition,
        string $versionLabel,
        string $gameMode,
        ?string $websiteUrl,
        ?string $discordUrl,
        string $listingState,
        DateTimeImmutable $now,
    ): void;

    public function transferOwnership(
        EntityId $serverId,
        ?EntityId $expectedOwnerUserId,
        ?EntityId $newOwnerUserId,
        EntityId $actorUserId,
        string $eventType,
        DateTimeImmutable $now,
    ): void;
}
