<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Ui;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Ui\Theme\PublishedThemeAssetService;
use Forwext\Core\Ui\Theme\ThemeDefinition;
use Forwext\Core\Ui\Theme\ThemePayload;
use Forwext\Core\Ui\Theme\ThemeRepository;
use Forwext\Core\Ui\Theme\ThemeRevision;
use Forwext\Core\Ui\Theme\ThemeTemplateCache;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PublishedThemeAssetServiceTest extends TestCase
{
    public function testOnlyCurrentPublishedRevisionCanServeCompiledAssets(): void
    {
        $directory = sys_get_temp_dir() . '/forwext-theme-assets-' . bin2hex(random_bytes(8));
        $themeId = EntityId::fromString(str_repeat('a', 32));
        $revisionId = EntityId::fromString(str_repeat('b', 32));
        $actor = EntityId::fromString(str_repeat('c', 32));
        $at = new DateTimeImmutable('2026-09-23T18:00:00+00:00');
        $theme = new ThemeDefinition(
            $themeId,
            'default',
            'Default',
            null,
            $revisionId,
            $revisionId,
            $at,
            $at,
        );
        $revision = new ThemeRevision(
            $revisionId,
            $themeId,
            new ThemePayload([], [], 'body{color:white}', 'globalThis.themeReady=true;'),
            $actor,
            $at,
        );

        $repository = new PublishedAssetThemeRepository($theme, $revision);
        $cache = new ThemeTemplateCache($directory);

        try {
            $cache->compile('default', $revision);
            $service = new PublishedThemeAssetService($repository, $cache);

            self::assertSame('body{color:white}', $service->asset('default', $revisionId, 'css'));
            self::assertSame('globalThis.themeReady=true;', $service->asset('default', $revisionId, 'js'));

            $this->expectException(InvalidArgumentException::class);
            $service->asset('default', EntityId::fromString(str_repeat('d', 32)), 'css');
        } finally {
            self::removeDirectory($directory);
        }
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $directory . '/' . $item;
            is_dir($path) ? self::removeDirectory($path) : @unlink($path);
        }
        @rmdir($directory);
    }
}

final class PublishedAssetThemeRepository implements ThemeRepository
{
    public function __construct(
        private ThemeDefinition $theme,
        private ThemeRevision $revision,
    ) {
    }

    public function all(): array
    {
        return [$this->theme];
    }

    public function findByKey(string $key): ?ThemeDefinition
    {
        return $key === $this->theme->key ? $this->theme : null;
    }

    public function findById(EntityId $themeId): ?ThemeDefinition
    {
        return $themeId->equals($this->theme->themeId) ? $this->theme : null;
    }

    public function revision(EntityId $revisionId): ?ThemeRevision
    {
        return $revisionId->equals($this->revision->revisionId) ? $this->revision : null;
    }

    public function revisions(EntityId $themeId, int $limit = 50): array
    {
        return $themeId->equals($this->theme->themeId) ? [$this->revision] : [];
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
