<?php

declare(strict_types=1);

namespace Forwext\Core\Minecraft\Server;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class MinecraftServerStatistics
{
    /** @param array<string,int> $dailyVotes */
    public function __construct(
        public int $totalVotes,
        public int $votesLast30Days,
        public int $publishedUpdates,
        public ?DateTimeImmutable $lastVoteAt,
        public array $dailyVotes,
    ) {
        if ($totalVotes < 0 || $votesLast30Days < 0 || $publishedUpdates < 0 || $votesLast30Days > $totalVotes) {
            throw new InvalidArgumentException('Minecraft server statistics are invalid.');
        }
        if (count($dailyVotes) !== 30) {
            throw new InvalidArgumentException('Minecraft server vote trend must contain 30 days.');
        }
        foreach ($dailyVotes as $day=>$votes) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day) !== 1 || !is_int($votes) || $votes < 0) {
                throw new InvalidArgumentException('Minecraft server vote trend bucket is invalid.');
            }
        }
    }
}
