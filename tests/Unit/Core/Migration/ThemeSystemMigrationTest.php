<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Migration;

use Forwext\Core\Install\CoreMigrationRegistry;
use Forwext\Database\Migrations\Core\CreateLayoutBuilderSystem;
use Forwext\Database\Migrations\Core\CreateThemeTemplateLanguageRevisionSystem;
use PHPUnit\Framework\TestCase;

final class ThemeSystemMigrationTest extends TestCase
{
    public function testThemeMigrationIsRegisteredAfterLayoutBuilder(): void
    {
        $classes = array_map(static fn ($migration): string => $migration::class, CoreMigrationRegistry::all());

        $layout = array_search(CreateLayoutBuilderSystem::class, $classes, true);
        $theme = array_search(CreateThemeTemplateLanguageRevisionSystem::class, $classes, true);

        self::assertIsInt($layout);
        self::assertIsInt($theme);
        self::assertGreaterThan($layout, $theme);
    }

    public function testMigrationContainsThemeAndImmutableRevisionTables(): void
    {
        $root = dirname(__DIR__, 4);
        $source = (string) file_get_contents(
            $root . '/database/migrations/core/CreateThemeTemplateLanguageRevisionSystem.php',
        );

        self::assertStringContainsString('forwext_themes', $source);
        self::assertStringContainsString('forwext_theme_revisions', $source);
        self::assertStringContainsString('parent_theme_id', $source);
        self::assertStringContainsString('staging_revision_id', $source);
        self::assertStringContainsString('published_revision_id', $source);
        self::assertStringContainsString('checksum_sha256', $source);
    }
}
