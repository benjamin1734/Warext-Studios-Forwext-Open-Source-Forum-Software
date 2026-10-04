<?php

declare(strict_types=1);

namespace Forwext\Core\Minecraft\Server;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class MinecraftServerVoteFeedEntry
{
    public function __construct(
        public EntityId $voteId,
        public EntityId $serverId,
        public EntityId $voterUserId,
        public string $accountUsername,
        public DateTimeImmutable $createdAt,
    ) {
        UserId::assert($voterUserId);
        if ($accountUsername === '' || mb_strlen($accountUsername) > 64) {
            throw new InvalidArgumentException('Minecraft vote feed account username is invalid.');
        }
    }
}
