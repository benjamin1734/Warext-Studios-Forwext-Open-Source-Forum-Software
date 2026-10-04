<?php

declare(strict_types=1);

namespace Forwext\Core\Minecraft\Server;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class MinecraftServerVoteIntegration
{
    public function __construct(
        public EntityId $serverId,
        public bool $enabled,
        public ?string $tokenPrefix,
        public ?DateTimeImmutable $lastRotatedAt,
        public ?EntityId $updatedByUserId,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
        if ($updatedByUserId !== null) {
            UserId::assert($updatedByUserId);
        }
        if ($tokenPrefix !== null && preg_match('/^[A-Za-z0-9_-]{6,12}$/D', $tokenPrefix) !== 1) {
            throw new InvalidArgumentException('Minecraft vote integration token prefix is invalid.');
        }
        if ($enabled && $tokenPrefix === null) {
            throw new InvalidArgumentException('Enabled Minecraft vote integration requires a token.');
        }
    }

    public function hasToken(): bool
    {
        return $this->tokenPrefix !== null;
    }
}
