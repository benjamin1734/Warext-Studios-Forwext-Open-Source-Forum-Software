<?php

declare(strict_types=1);

namespace Forwext\Core\Minecraft\Server;

use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class MinecraftServerService
{
    public function __construct(private MinecraftServerRepository $servers)
    {
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
}
