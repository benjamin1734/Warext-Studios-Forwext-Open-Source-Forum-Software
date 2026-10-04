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

    public function voteSummary(
        EntityId $serverId,
        ?EntityId $actor,
        DateTimeImmutable $now,
    ): MinecraftServerVoteSummary {
        return $this->servers->voteSummary($serverId, $actor, $now);
    }

    public function canVote(EntityId $actor, MinecraftServerVoteSummary $summary): bool
    {
        return !$summary->votedToday
            && $this->gate($actor)->allows(self::permission('minecraft_server.vote'));
    }

    public function vote(EntityId $actor, EntityId $serverId, DateTimeImmutable $now): bool
    {
        $this->gate($actor)->require(self::permission('minecraft_server.vote'));
        if ($this->servers->publicById($serverId) === null) {
            throw new InvalidArgumentException('Minecraft server is unavailable for voting.');
        }
        $recorded = $this->servers->castVote($serverId, $actor, $now);
        if (!$recorded && $this->servers->publicById($serverId) === null) {
            throw new InvalidArgumentException('Minecraft server is unavailable for voting.');
        }
        return $recorded;
    }

    /** @return list<MinecraftServerUpdate> */
    public function updates(EntityId $serverId, int $limit = 20, int $offset = 0): array
    {
        if ($this->servers->publicById($serverId) === null) {
            throw new InvalidArgumentException('Minecraft server is unavailable.');
        }
        return $this->servers->publicUpdates($serverId, $limit, $offset);
    }

    public function statistics(EntityId $serverId, DateTimeImmutable $now): MinecraftServerStatistics
    {
        if ($this->servers->publicById($serverId) === null) {
            throw new InvalidArgumentException('Minecraft server is unavailable.');
        }
        return $this->servers->statistics($serverId, $now);
    }

    /** @return list<MinecraftServerUpdate> */
    public function managementUpdates(EntityId $actor, EntityId $serverId, int $limit = 100): array
    {
        $this->managementDetail($actor, $serverId);
        return $this->servers->managementUpdates($serverId, $limit);
    }

    public function publishUpdate(
        EntityId $actor,
        EntityId $serverId,
        string $title,
        string $body,
        DateTimeImmutable $now,
    ): EntityId {
        $server = $this->managementDetail($actor, $serverId);
        $manageAny = $this->gate($actor)->allows(self::permission('minecraft_server.manage_any'));
        $title = trim($title);
        $body = trim($body);
        $updateId = EntityId::fromString(bin2hex(random_bytes(16)));
        $update = new MinecraftServerUpdate(
            $updateId,
            $serverId,
            $actor,
            $title,
            $body,
            'published',
            $now,
            $now,
        );
        $this->servers->createUpdate($update, $manageAny ? null : $server->ownerUserId);
        return $updateId;
    }

    public function changeUpdateState(
        EntityId $actor,
        EntityId $serverId,
        EntityId $updateId,
        string $state,
        DateTimeImmutable $now,
    ): void {
        $server = $this->managementDetail($actor, $serverId);
        $manageAny = $this->gate($actor)->allows(self::permission('minecraft_server.manage_any'));
        if (!in_array($state, ['published','hidden'], true)) {
            throw new InvalidArgumentException('Minecraft server update state is invalid.');
        }
        if (!$this->servers->setUpdateState($serverId, $updateId, $state, $now, $manageAny ? null : $server->ownerUserId)) {
            throw new InvalidArgumentException('Minecraft server update was not found.');
        }
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
        if (!$gate->allows(self::permission('minecraft_server.manage_own'))) {
            return false;
        }
        if ($server->ownerUserId !== null && $server->ownerUserId->equals($actor)) {
            return true;
        }
        return $this->servers->teamRole($server->serverId, $actor) === 'manager';
    }

    /** @return list<MinecraftServerTeamMember> */
    public function publicTeam(EntityId $serverId): array
    {
        if ($this->servers->publicById($serverId) === null) {
            throw new InvalidArgumentException('Minecraft server is unavailable.');
        }
        return $this->servers->team($serverId);
    }

    public function canManageTeam(EntityId $actor, MinecraftServer $server): bool
    {
        $gate = $this->gate($actor);
        if ($gate->allows(self::permission('minecraft_server.manage_any'))) {
            return true;
        }
        return $server->ownerUserId !== null
            && $server->ownerUserId->equals($actor)
            && $gate->allows(self::permission('minecraft_server.team.manage'));
    }

    /** @return list<MinecraftServerTeamMember> */
    public function teamForManagement(EntityId $actor, EntityId $serverId): array
    {
        $this->requireTeamManagement($actor, $serverId);
        return $this->servers->team($serverId);
    }

    public function saveTeamMember(
        EntityId $actor,
        EntityId $serverId,
        EntityId $targetUserId,
        string $roleKey,
        ?string $publicTitle,
        DateTimeImmutable $now,
    ): void {
        $server = $this->requireTeamManagement($actor, $serverId);
        if ($server->ownerUserId !== null && $server->ownerUserId->equals($targetUserId)) {
            throw new InvalidArgumentException('Minecraft server owner cannot also be a team member.');
        }
        $publicTitle = self::optionalText($publicTitle, 64);
        $member = new MinecraftServerTeamMember(
            $serverId,
            $targetUserId,
            $roleKey,
            $publicTitle,
            $actor,
            $now,
            $now,
        );
        $manageAny = $this->gate($actor)->allows(self::permission('minecraft_server.manage_any'));
        $this->servers->upsertTeamMember($member, $manageAny ? null : $server->ownerUserId);
    }

    public function removeTeamMember(
        EntityId $actor,
        EntityId $serverId,
        EntityId $targetUserId,
        DateTimeImmutable $now,
    ): void {
        $server = $this->requireTeamManagement($actor, $serverId);
        $manageAny = $this->gate($actor)->allows(self::permission('minecraft_server.manage_any'));
        if (!$this->servers->removeTeamMember(
            $serverId,
            $targetUserId,
            $manageAny ? null : $server->ownerUserId,
            $actor,
            $now,
        )) {
            throw new InvalidArgumentException('Minecraft server team member was not found.');
        }
    }

    public function canManageVoteIntegration(EntityId $actor, MinecraftServer $server): bool
    {
        $gate = $this->gate($actor);
        if ($gate->allows(self::permission('minecraft_server.manage_any'))) {
            return true;
        }
        return $server->ownerUserId !== null
            && $server->ownerUserId->equals($actor)
            && $gate->allows(self::permission('minecraft_server.vote_integration.manage'));
    }

    public function voteIntegrationSettings(
        EntityId $actor,
        EntityId $serverId,
    ): ?MinecraftServerVoteIntegration {
        $this->requireVoteIntegrationManagement($actor, $serverId);
        return $this->servers->voteIntegration($serverId);
    }

    public function setVoteIntegrationEnabled(
        EntityId $actor,
        EntityId $serverId,
        bool $enabled,
        DateTimeImmutable $now,
    ): void {
        $server = $this->requireVoteIntegrationManagement($actor, $serverId);
        $integration = $this->servers->voteIntegration($serverId);
        if ($enabled && ($integration === null || !$integration->hasToken())) {
            throw new InvalidArgumentException('Create an integration token before enabling the vote feed.');
        }
        $manageAny = $this->gate($actor)->allows(self::permission('minecraft_server.manage_any'));
        $this->servers->saveVoteIntegration(
            $serverId,
            $enabled,
            null,
            null,
            false,
            $manageAny ? null : $server->ownerUserId,
            $actor,
            $now,
        );
    }

    public function rotateVoteIntegrationToken(
        EntityId $actor,
        EntityId $serverId,
        DateTimeImmutable $now,
    ): string {
        $server = $this->requireVoteIntegrationManagement($actor, $serverId);
        $existing = $this->servers->voteIntegration($serverId);
        $token = self::integrationToken();
        $manageAny = $this->gate($actor)->allows(self::permission('minecraft_server.manage_any'));
        $this->servers->saveVoteIntegration(
            $serverId,
            $existing?->enabled ?? false,
            hash('sha256', $token),
            substr($token, 0, 10),
            true,
            $manageAny ? null : $server->ownerUserId,
            $actor,
            $now,
        );
        return $token;
    }

    /** @return list<MinecraftServerVoteFeedEntry> */
    public function voteIntegrationFeed(EntityId $serverId, string $token, int $limit = 100): array
    {
        if (preg_match('/^[A-Za-z0-9_-]{40,128}$/D', $token) !== 1) {
            throw new InvalidArgumentException('Minecraft vote integration token is invalid.');
        }
        if (!$this->servers->acceptsVoteIntegrationToken($serverId, hash('sha256', $token))) {
            throw new InvalidArgumentException('Minecraft vote integration token is invalid.');
        }
        return $this->servers->voteFeed($serverId, $limit);
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
        $gate->require(self::permission('minecraft_server.manage_own'));
        if ($server->ownerUserId !== null && $server->ownerUserId->equals($actor)) {
            return;
        }
        if ($this->servers->teamRole($server->serverId, $actor) === 'manager') {
            return;
        }
        $gate->require(self::permission('minecraft_server.manage_any'));
    }

    private function requireTeamManagement(EntityId $actor, EntityId $serverId): MinecraftServer
    {
        $server = $this->servers->managementById($serverId);
        if ($server === null) {
            throw new InvalidArgumentException('Minecraft server was not found.');
        }
        $gate = $this->gate($actor);
        if ($gate->allows(self::permission('minecraft_server.manage_any'))) {
            return $server;
        }
        $gate->require(self::permission('minecraft_server.team.manage'));
        if ($server->ownerUserId === null || !$server->ownerUserId->equals($actor)) {
            $gate->require(self::permission('minecraft_server.manage_any'));
        }
        return $server;
    }

    private function requireVoteIntegrationManagement(EntityId $actor, EntityId $serverId): MinecraftServer
    {
        $server = $this->servers->managementById($serverId);
        if ($server === null) {
            throw new InvalidArgumentException('Minecraft server was not found.');
        }
        $gate = $this->gate($actor);
        if ($gate->allows(self::permission('minecraft_server.manage_any'))) {
            return $server;
        }
        $gate->require(self::permission('minecraft_server.vote_integration.manage'));
        if ($server->ownerUserId === null || !$server->ownerUserId->equals($actor)) {
            $gate->require(self::permission('minecraft_server.manage_any'));
        }
        return $server;
    }

    private static function integrationToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
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
