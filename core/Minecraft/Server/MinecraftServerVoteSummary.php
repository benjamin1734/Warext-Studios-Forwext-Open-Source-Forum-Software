<?php

declare(strict_types=1);

namespace Forwext\Core\Minecraft\Server;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class MinecraftServerVoteSummary
{
    public function __construct(
        public int $totalVotes,
        public int $votesLast30Days,
        public bool $votedToday,
        public ?DateTimeImmutable $lastVoteAt,
    ) {
        if ($totalVotes < 0 || $votesLast30Days < 0 || $votesLast30Days > $totalVotes) {
            throw new InvalidArgumentException('Minecraft server vote summary is invalid.');
        }
        if ($votedToday && $lastVoteAt === null) {
            throw new InvalidArgumentException('Minecraft server daily vote state is invalid.');
        }
    }
}
