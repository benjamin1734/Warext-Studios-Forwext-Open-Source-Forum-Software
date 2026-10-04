<?php

declare(strict_types=1);

namespace Forwext\Core\Minecraft\Server;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class MinecraftServerUpdate
{
    public function __construct(
        public EntityId $updateId,
        public EntityId $serverId,
        public ?EntityId $authorUserId,
        public string $title,
        public string $body,
        public string $state,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
        if ($title === '' || mb_strlen($title) > 160) {
            throw new InvalidArgumentException('Minecraft server update title is invalid.');
        }
        if ($body === '' || mb_strlen($body) > 10000) {
            throw new InvalidArgumentException('Minecraft server update body is invalid.');
        }
        if (!in_array($state, ['published','hidden'], true)) {
            throw new InvalidArgumentException('Minecraft server update state is invalid.');
        }
    }

    public function published(): bool
    {
        return $this->state === 'published';
    }
}
