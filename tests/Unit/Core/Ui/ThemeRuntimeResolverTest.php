<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Ui;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Ui\Theme\ThemeDefinition;
use Forwext\Core\Ui\Theme\ThemePayload;
use Forwext\Core\Ui\Theme\ThemeRepository;
use Forwext\Core\Ui\Theme\ThemeRevision;
use Forwext\Core\Ui\Theme\ThemeRuntimeResolver;
use PHPUnit\Framework\TestCase;

final class ThemeRuntimeResolverTest extends TestCase
{
    public function testPublishedParentAndChildPayloadsMergeWithoutUsingStagingParent(): void
    {
        $actor = EntityId::fromString(str_repeat('f', 32));
        $parentId = EntityId::fromString(str_repeat('a', 32));
        $childId = EntityId::fromString(str_repeat('b', 32));
        $parentPublishedId = EntityId::fromString(str_repeat('c', 32));
        $parentStagingId = EntityId::fromString(str_repeat('d', 32));
        $childPublishedId = EntityId::fromString(str_repeat('e', 32));
        $at = new DateTimeImmutable('2026-09-23T18:00:00+00:00');

        $parent = new ThemeDefinition(
            $parentId,
            'base',
            'Base',
            null,
            $parentStagingId,
            $parentPublishedId,
            $at,
            $at,
        );
        $child = new ThemeDefinition(
            $childId,
            'child',
            'Child',
            $parentId,
            $childPublishedId,
            $childPublishedId,
            $at,
            $at,
        );

        $repository = new MemoryThemeRepository(
            [$parent, $child],
            [
                new ThemeRevision(
                    $parentPublishedId,
                    $parentId,
                    new ThemePayload(
                        ['page.shell' => 'Published parent', 'page.footer' => 'Parent footer'],
                        ['tr' => ['nav.home' => 'Ana Sayfa']],
                    ),
                    $actor,
                    $at,
                ),
                new ThemeRevision(
                    $parentStagingId,
                    $parentId,
                    new ThemePayload(['page.shell' => 'UNPUBLISHED parent'], []),
                    $actor,
                    $at,
                ),
                new ThemeRevision(
                    $childPublishedId,
                    $childId,
                    new ThemePayload(
                        ['page.footer' => 'Child footer'],
                        ['tr' => ['nav.search' => 'Ara']],
                    ),
                    $actor,
                    $at,
                ),
            ],
        );

        $payload = (new ThemeRuntimeResolver($repository))->effectivePublishedPayload('child');

        self::assertSame('Published parent', $payload->templates['page.shell']);
        self::assertSame('Child footer', $payload->templates['page.footer']);
        self::assertSame('Ana Sayfa', $payload->phrase('tr', 'nav.home'));
        self::assertSame('Ara', $payload->phrase('tr', 'nav.search'));
        self::assertStringNotContainsString('UNPUBLISHED', implode('', $payload->templates));
    }
}

final class MemoryThemeRepository implements ThemeRepository
{
    /** @var array<string,ThemeDefinition> */
    private array $themesByKey = [];

    /** @var array<string,ThemeDefinition> */
    private array $themesById = [];

    /** @var array<string,ThemeRevision> */
    private array $revisionsById = [];

    /**
     * @param list<ThemeDefinition> $themes
     * @param list<ThemeRevision> $revisions
     */
    public function __construct(array $themes, array $revisions)
    {
        foreach ($themes as $theme) {
            $this->themesByKey[$theme->key] = $theme;
            $this->themesById[$theme->themeId->value()] = $theme;
        }
        foreach ($revisions as $revision) {
            $this->revisionsById[$revision->revisionId->value()] = $revision;
        }
    }

    public function all(): array
    {
        return array_values($this->themesByKey);
    }

    public function findByKey(string $key): ?ThemeDefinition
    {
        return $this->themesByKey[$key] ?? null;
    }

    public function findById(EntityId $themeId): ?ThemeDefinition
    {
        return $this->themesById[$themeId->value()] ?? null;
    }

    public function revision(EntityId $revisionId): ?ThemeRevision
    {
        return $this->revisionsById[$revisionId->value()] ?? null;
    }

    public function revisions(EntityId $themeId, int $limit = 50): array
    {
        return array_values(array_filter(
            $this->revisionsById,
            static fn (ThemeRevision $revision): bool => $revision->themeId->equals($themeId),
        ));
    }

    public function saveStaging(
        ThemeDefinition $theme,
        ThemeRevision $revision,
        EntityId $actor,
        DateTimeImmutable $updatedAt,
    ): void {
        throw new \LogicException('Not used in this test.');
    }

    public function publish(
        EntityId $themeId,
        EntityId $expectedStagingRevisionId,
        EntityId $actor,
        DateTimeImmutable $updatedAt,
    ): bool {
        throw new \LogicException('Not used in this test.');
    }

    public function pointStaging(
        EntityId $themeId,
        EntityId $revisionId,
        EntityId $actor,
        DateTimeImmutable $updatedAt,
    ): bool {
        throw new \LogicException('Not used in this test.');
    }
}
