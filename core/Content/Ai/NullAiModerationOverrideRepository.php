<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai;

use DateTimeImmutable;

final readonly class NullAiModerationOverrideRepository implements AiModerationOverrideRepository
{
    public function active(string $contentFingerprint, DateTimeImmutable $at): ?AiModerationHumanOverride
    {
        AiModerationFingerprint::assert($contentFingerprint);
        return null;
    }

    public function save(AiModerationHumanOverride $override): void
    {
    }

    public function delete(string $contentFingerprint): bool
    {
        AiModerationFingerprint::assert($contentFingerprint);
        return false;
    }
}
