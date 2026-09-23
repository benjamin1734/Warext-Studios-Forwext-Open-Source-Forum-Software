<?php

declare(strict_types=1);

namespace Forwext\Core\Admin\Navigation;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

interface AdminNavigationPreferenceRepository
{
    public function load(EntityId $userId): AdminNavigationPreferences;

    public function save(
        EntityId $userId,
        AdminNavigationPreferences $preferences,
        DateTimeImmutable $updatedAt,
    ): void;
}
