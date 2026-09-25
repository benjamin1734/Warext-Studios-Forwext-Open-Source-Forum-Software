<?php

declare(strict_types=1);

namespace Forwext\Core\Addon\Backend;

use DateTimeImmutable;
use Forwext\Core\Addon\AddonId;
use Forwext\Core\Domain\Entity\EntityId;

interface AddonSettingStore
{
    public function resolve(AddonId $addonId, AddonSettingDefinition $definition): bool|int|string;

    public function save(
        AddonId $addonId,
        AddonSettingDefinition $definition,
        mixed $value,
        EntityId $actor,
        DateTimeImmutable $at,
    ): bool|int|string;

    public function reset(AddonId $addonId, AddonSettingDefinition $definition): void;
}
