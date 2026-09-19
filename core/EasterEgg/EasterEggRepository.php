<?php

declare(strict_types=1);

namespace Forwext\Core\EasterEgg;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

interface EasterEggRepository
{
    public function globalEnabled(): bool;

    public function setGlobalEnabled(bool $enabled, DateTimeImmutable $at): void;

    /** @return list<EasterEggDefinition> */
    public function all(int $limit = 200): array;

    public function find(EntityId $easterEggId): ?EasterEggDefinition;

    /** @return list<EasterEggDefinition> */
    public function activeAt(DateTimeImmutable $at, int $limit = 50): array;

    public function save(EasterEggDefinition $definition): void;

    /** @return list<EntityId> */
    public function groupIds(EntityId $easterEggId): array;

    /** @param list<EntityId> $groupIds */
    public function replaceGroups(EntityId $easterEggId, array $groupIds): void;

    /** @return list<EasterEggGroupOption> */
    public function availableGroups(): array;
}
