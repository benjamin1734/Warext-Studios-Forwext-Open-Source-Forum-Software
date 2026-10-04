<?php

declare(strict_types=1);

namespace Forwext\Core\Minecraft\Server;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class MinecraftServerSeason
{
    public function __construct(
        public EntityId $seasonId,
        public string $slug,
        public string $name,
        public string $summary,
        public string $state,
        public DateTimeImmutable $startsAt,
        public DateTimeImmutable $endsAt,
        public int $serverCount,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,119}$/D', $slug) !== 1) {
            throw new InvalidArgumentException('Minecraft season slug is invalid.');
        }
        if ($name === '' || mb_strlen($name) > 120 || mb_strlen($summary) > 500) {
            throw new InvalidArgumentException('Minecraft season text fields are invalid.');
        }
        if (!in_array($state, ['upcoming','active','closed'], true)) {
            throw new InvalidArgumentException('Minecraft season state is invalid.');
        }
        if ($endsAt <= $startsAt || $serverCount < 0) {
            throw new InvalidArgumentException('Minecraft season schedule is invalid.');
        }
    }
}
