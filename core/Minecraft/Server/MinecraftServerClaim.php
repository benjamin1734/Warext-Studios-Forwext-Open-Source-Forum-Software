<?php

declare(strict_types=1);

namespace Forwext\Core\Minecraft\Server;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class MinecraftServerClaim
{
    public function __construct(
        public EntityId $claimId,
        public EntityId $serverId,
        public EntityId $claimantUserId,
        public string $proofNote,
        public string $state,
        public ?EntityId $reviewedByUserId,
        public ?string $reviewNote,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $reviewedAt,
    ) {
        if ($proofNote === '' || mb_strlen($proofNote) > 1000) {
            throw new InvalidArgumentException('Minecraft server ownership proof note is invalid.');
        }
        if (!in_array($state, ['pending','approved','rejected','cancelled'], true)) {
            throw new InvalidArgumentException('Minecraft server ownership claim state is invalid.');
        }
        if ($reviewNote !== null && mb_strlen($reviewNote) > 1000) {
            throw new InvalidArgumentException('Minecraft server ownership review note is invalid.');
        }
    }
}
