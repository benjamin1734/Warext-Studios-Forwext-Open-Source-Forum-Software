<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai;

use DateTimeImmutable;

interface AiModerationOverrideRepository
{
    public function active(string $contentFingerprint, DateTimeImmutable $at): ?AiModerationHumanOverride;

    public function save(AiModerationHumanOverride $override): void;

    public function delete(string $contentFingerprint): bool;
}
