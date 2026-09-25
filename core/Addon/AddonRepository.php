<?php

declare(strict_types=1);

namespace Forwext\Core\Addon;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

interface AddonRepository
{
    public function find(AddonId $id): ?AddonInstallation;

    /** @return array<string,AddonInstallation> */
    public function all(): array;

    public function save(AddonInstallation $installation, EntityId $actor, DateTimeImmutable $at): void;
}
