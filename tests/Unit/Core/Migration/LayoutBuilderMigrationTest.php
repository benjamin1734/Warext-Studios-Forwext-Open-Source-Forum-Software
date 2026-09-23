<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Migration;

use Forwext\Core\Install\CoreMigrationRegistry;
use Forwext\Database\Migrations\Core\CreateAnalyticsReportBuilder;
use Forwext\Database\Migrations\Core\CreateLayoutBuilderSystem;
use PHPUnit\Framework\TestCase;

final class LayoutBuilderMigrationTest extends TestCase
{
    public function testLayoutBuilderMigrationIsRegisteredAfterAnalytics(): void
    {
        $classes = array_map(static fn ($migration): string => $migration::class, CoreMigrationRegistry::all());

        $analytics = array_search(CreateAnalyticsReportBuilder::class, $classes, true);
        $layout = array_search(CreateLayoutBuilderSystem::class, $classes, true);

        self::assertIsInt($analytics);
        self::assertIsInt($layout);
        self::assertGreaterThan($analytics, $layout);
    }

    public function testMigrationDeclaresLayoutAndImmutableRevisionTables(): void
    {
        $root = dirname(__DIR__, 4);
        $source = (string) file_get_contents($root . '/database/migrations/core/CreateLayoutBuilderSystem.php');

        self::assertStringContainsString('forwext_ui_layouts', $source);
        self::assertStringContainsString('forwext_ui_layout_revisions', $source);
        self::assertStringContainsString('draft_revision_id', $source);
        self::assertStringContainsString('published_revision_id', $source);
        self::assertStringContainsString('checksum_sha256', $source);
    }
}
