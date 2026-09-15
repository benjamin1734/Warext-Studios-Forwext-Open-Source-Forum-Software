<?php

declare(strict_types=1);

namespace Forwext\Core\Profile\Music;

use Forwext\Core\Domain\Entity\EntityId;

interface ProfileMusicStore
{
    public function find(EntityId $userId): ?ProfileMusicSettings;

    public function save(ProfileMusicSettings $settings): void;

    public function saveModeration(ProfileMusicSettings $settings, ProfileMusicModerationEvent $event): void;
}
