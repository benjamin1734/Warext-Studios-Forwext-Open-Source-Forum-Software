<?php

declare(strict_types=1);

namespace Forwext\Core\Minecraft\Server;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class MinecraftServerTeamMember
{
    public function __construct(
        public EntityId $serverId,
        public EntityId $userId,
        public string $roleKey,
        public ?string $publicTitle,
        public ?EntityId $addedByUserId,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
        UserId::assert($userId);
        if ($addedByUserId !== null) {
            UserId::assert($addedByUserId);
        }
        if (!in_array($roleKey, ['manager','member'], true)) {
            throw new InvalidArgumentException('Minecraft server team role is invalid.');
        }
        if ($publicTitle !== null && ($publicTitle === '' || mb_strlen($publicTitle) > 64)) {
            throw new InvalidArgumentException('Minecraft server team title is invalid.');
        }
    }

    public function canManage(): bool
    {
        return $this->roleKey === 'manager';
    }
}
