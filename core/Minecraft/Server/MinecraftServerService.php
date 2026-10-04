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
}
