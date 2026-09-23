<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Theme;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

interface ThemeRepository
{
    /** @return list<ThemeDefinition> */
    public function all(): array;

    public function findByKey(string $key): ?ThemeDefinition;

    public function findById(EntityId $themeId): ?ThemeDefinition;

    public function revision(EntityId $revisionId): ?ThemeRevision;

    /** @return list<ThemeRevision> */
    public function revisions(EntityId $themeId, int $limit = 50): array;

    public function saveStaging(
        ThemeDefinition $theme,
        ThemeRevision $revision,
        EntityId $actor,
        DateTimeImmutable $updatedAt,
    ): void;

    public function publish(
        EntityId $themeId,
        EntityId $expectedStagingRevisionId,
        EntityId $actor,
        DateTimeImmutable $updatedAt,
    ): bool;

    public function pointStaging(
        EntityId $themeId,
        EntityId $revisionId,
        EntityId $actor,
        DateTimeImmutable $updatedAt,
    ): bool;
}
