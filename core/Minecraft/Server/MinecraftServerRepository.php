<?php

declare(strict_types=1);

namespace Forwext\Core\Minecraft\Server;

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
}
