<?php

declare(strict_types=1);

namespace Forwext\Core\Minecraft\Server;

use DateTimeImmutable;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class MinecraftServerService
{
    public function __construct(
        private MinecraftServerRepository $servers,
        private PermissionAuthorizer $authorizer,
    ) {
    }

    /** @return list<MinecraftServer> */
    public function directory(
        ?string $query = null,
        ?string $edition = null,
        int $limit = 30,
        int $offset = 0,
    ): array {
        if ($query !== null) {
            $query = trim($query);
            if ($query === '' || mb_strlen($query) > 120) {
                throw new InvalidArgumentException('Minecraft server search query is invalid.');
            }
        }
        if ($edition !== null && !in_array($edition, ['java','bedrock','crossplay'], true)) {
            throw new InvalidArgumentException('Minecraft server edition filter is invalid.');
        }
        if ($limit < 1 || $limit > 100 || $offset < 0 || $offset > 1_000_000) {
            throw new InvalidArgumentException('Minecraft server pagination is invalid.');
        }

        return $this->servers->publicDirectory($query, $edition, $limit, $offset);
    }

    public function detail(EntityId $serverId): ?MinecraftServer
    {
        return $this->servers->publicById($serverId);
    }

    /** @param list<EntityId> $serverIds @return list<MinecraftServer> */
    public function compare(array $serverIds): array
    {
        if (count($serverIds) < 2 || count($serverIds) > 4) {
            throw new InvalidArgumentException('Minecraft server comparison requires two to four servers.');
        }
        $unique = [];
        foreach ($serverIds as $serverId) {
            if (!$serverId instanceof EntityId) {
                throw new InvalidArgumentException('Minecraft server comparison id is invalid.');
            }
            $unique[$serverId->value()] = $serverId;
        }
        if (count($unique) !== count($serverIds)) {
            throw new InvalidArgumentException('Minecraft server comparison contains duplicate servers.');
        }

        $servers = $this->servers->publicByIds(array_values($unique));
        if (count($servers) !== count($serverIds)) {
            throw new InvalidArgumentException('Minecraft server comparison contains unavailable servers.');
        }
        return $servers;
    }

    /** @return list<MinecraftServerSeason> */
    public function seasons(
        ?string $state = null,
        int $limit = 30,
        int $offset = 0,
    ): array {
        if ($state !== null && !in_array($state, ['upcoming','active','closed'], true)) {
            throw new InvalidArgumentException('Minecraft season state filter is invalid.');
        }
        if ($limit < 1 || $limit > 100 || $offset < 0 || $offset > 1_000_000) {
            throw new InvalidArgumentException('Minecraft season pagination is invalid.');
        }
        return $this->servers->publicSeasons($state, $limit, $offset);
    }

    /** @return list<MinecraftServer> */
    public function manageable(EntityId $actor, int $limit = 100): array
    {
        $gate = $this->gate($actor);
        if ($gate->allows(self::permission('minecraft_server.manage_any'))) {
            return $this->servers->managementDirectory(null, $limit);
        }
        $gate->require(self::permission('minecraft_server.manage_own'));
        return $this->servers->managementDirectory($actor, $limit);
    }

    public function managementDetail(EntityId $actor, EntityId $serverId): MinecraftServer
    {
        $server = $this->servers->managementById($serverId);
        if ($server === null) {
            throw new InvalidArgumentException('Minecraft server was not found.');
        }
        $this->requireManagement($actor, $server);
        return $server;
    }

    public function canManage(EntityId $actor, MinecraftServer $server): bool
    {
        $gate = $this->gate($actor);
        if ($gate->allows(self::permission('minecraft_server.manage_any'))) {
            return true;
        }
        return $server->ownerUserId !== null
            && $server->ownerUserId->equals($actor)
            && $gate->allows(self::permission('minecraft_server.manage_own'));
    }

    public function canClaim(EntityId $actor, MinecraftServer $server): bool
    {
        return $server->ownerUserId === null
            && $this->gate($actor)->allows(self::permission('minecraft_server.claim'));
    }

    public function canReviewClaims(EntityId $actor): bool
    {
        return $this->gate($actor)->allows(self::permission('minecraft_server.manage_any'));
    }

    /** @return list<MinecraftServerClaim> */
    public function claimsForActor(EntityId $actor, EntityId $serverId): array
    {
        $this->gate($actor)->require(self::permission('minecraft_server.claim'));
        return $this->servers->claims($actor, $serverId, 30);
    }

    /** @return list<MinecraftServerClaim> */
    public function claimsForManagement(EntityId $actor, ?EntityId $serverId = null): array
    {
        $this->gate($actor)->require(self::permission('minecraft_server.manage_any'));
        return $this->servers->claims(null, $serverId, 100);
    }

    public function update(
        EntityId $actor,
        EntityId $serverId,
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
    ): void {
        $server = $this->managementDetail($actor, $serverId);
        $manageAny = $this->gate($actor)->allows(self::permission('minecraft_server.manage_any'));
        if (!$manageAny && $listingState === 'suspended') {
            throw new InvalidArgumentException('Server owners cannot suspend directory entries.');
        }
        if (!$manageAny && !in_array($listingState, ['draft','published'], true)) {
            throw new InvalidArgumentException('Minecraft server listing state is invalid.');
        }

        $name = trim($name);
        $summary = trim($summary);
        $description = trim($description);
        $host = trim($host);
        $versionLabel = trim($versionLabel);
        $gameMode = trim($gameMode);
        $websiteUrl = self::optionalUrl($websiteUrl);
        $discordUrl = self::optionalUrl($discordUrl);
        if (mb_strlen($versionLabel) > 64 || mb_strlen($gameMode) > 64) {
            throw new InvalidArgumentException('Minecraft server version or game mode is too long.');
        }

        new MinecraftServer(
            $server->serverId,
            $server->ownerUserId,
            $server->slug,
            $name,
            $summary,
            $description,
            $host,
            $port,
            $edition,
            $versionLabel,
            $gameMode,
            $websiteUrl,
            $discordUrl,
            $listingState,
            $server->verificationState,
            $server->reachability,
            $server->onlinePlayers,
            $server->maxPlayers,
            $server->latencyMs,
            $server->motd,
            $server->statusCheckedAt,
            $server->createdAt,
            $now,
        );

        $this->servers->updateDetails(
            $serverId,
            $server->ownerUserId,
            $actor,
            $name,
            $summary,
            $description,
            $host,
            $port,
            $edition,
            $versionLabel,
            $gameMode,
            $websiteUrl,
            $discordUrl,
            $listingState,
            $now,
        );
    }

    public function submitClaim(
        EntityId $actor,
        EntityId $serverId,
        string $proofNote,
        DateTimeImmutable $now,
    ): EntityId {
        $gate = $this->gate($actor);
        $gate->require(self::permission('minecraft_server.claim'));
        $server = $this->servers->managementById($serverId);
        if ($server === null || $server->ownerUserId !== null) {
            throw new InvalidArgumentException('Minecraft server cannot be claimed.');
        }
        $proofNote = trim($proofNote);
        if (mb_strlen($proofNote) < 20 || mb_strlen($proofNote) > 1000) {
            throw new InvalidArgumentException('Ownership proof must be between 20 and 1000 characters.');
        }

        $claimId = EntityId::fromString(bin2hex(random_bytes(16)));
        $claim = new MinecraftServerClaim(
            $claimId,
            $serverId,
            $actor,
            $proofNote,
            'pending',
            null,
            null,
            $now,
            $now,
            null,
        );
        $this->servers->createClaim($claim);
        return $claimId;
    }

    public function reviewClaim(
        EntityId $actor,
        EntityId $claimId,
        bool $approve,
        ?string $reviewNote,
        DateTimeImmutable $now,
    ): void {
        $this->gate($actor)->require(self::permission('minecraft_server.manage_any'));
        $reviewNote = self::optionalText($reviewNote, 1000);
        $this->servers->reviewClaim($claimId, $actor, $approve, $reviewNote, $now);
    }

    public function transfer(
        EntityId $actor,
        EntityId $serverId,
        EntityId $targetOwner,
        DateTimeImmutable $now,
    ): void {
        $server = $this->managementDetail($actor, $serverId);
        if ($server->ownerUserId === null) {
            throw new InvalidArgumentException('Unowned Minecraft server cannot be transferred.');
        }
        if ($server->ownerUserId->equals($targetOwner)) {
            throw new InvalidArgumentException('Minecraft server already belongs to that account.');
        }
        $gate = $this->gate($actor);
        if (!$gate->allows(self::permission('minecraft_server.manage_any'))) {
            if (!$server->ownerUserId->equals($actor)) {
                $gate->require(self::permission('minecraft_server.manage_any'));
            }
            $gate->require(self::permission('minecraft_server.transfer'));
        }
        $this->servers->transferOwnership(
            $serverId,
            $server->ownerUserId,
            $targetOwner,
            $actor,
            'ownership_transferred',
            $now,
        );
    }

    public function release(EntityId $actor, EntityId $serverId, DateTimeImmutable $now): void
    {
        $server = $this->managementDetail($actor, $serverId);
        if ($server->ownerUserId === null) {
            throw new InvalidArgumentException('Minecraft server is already unowned.');
        }
        $gate = $this->gate($actor);
        if (!$gate->allows(self::permission('minecraft_server.manage_any'))) {
            if (!$server->ownerUserId->equals($actor)) {
                $gate->require(self::permission('minecraft_server.manage_any'));
            }
            $gate->require(self::permission('minecraft_server.transfer'));
        }
        $this->servers->transferOwnership(
            $serverId,
            $server->ownerUserId,
            null,
            $actor,
            'ownership_released',
            $now,
        );
    }

    private function requireManagement(EntityId $actor, MinecraftServer $server): void
    {
        $gate = $this->gate($actor);
        if ($gate->allows(self::permission('minecraft_server.manage_any'))) {
            return;
        }
        if ($server->ownerUserId === null || !$server->ownerUserId->equals($actor)) {
            $gate->require(self::permission('minecraft_server.manage_any'));
        }
        $gate->require(self::permission('minecraft_server.manage_own'));
    }

    private function gate(EntityId $actor): PermissionGate
    {
        return new PermissionGate($this->authorizer, $actor);
    }

    private static function permission(string $key): PermissionKey
    {
        return PermissionKey::fromString($key);
    }

    private static function optionalUrl(?string $value): ?string
    {
        $value = self::optionalText($value, 1000);
        if ($value === null) {
            return null;
        }
        $parsed = parse_url($value);
        $scheme = is_array($parsed) ? strtolower((string) ($parsed['scheme'] ?? '')) : '';
        $host = is_array($parsed) ? (string) ($parsed['host'] ?? '') : '';
        if (!in_array($scheme, ['http','https'], true) || $host === '' || filter_var($value, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Minecraft server external URL is invalid.');
        }
        return $value;
    }

    private static function optionalText(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $max) {
            throw new InvalidArgumentException('Minecraft server text value is too long.');
        }
        return $value;
    }
}
