<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Migration;

use Forwext\Core\Install\CoreMigrationRegistry;
use Forwext\Database\Migrations\Core\CreateAdminInformationArchitecture;
use Forwext\Database\Migrations\Core\CreateThemeTemplateLanguageRevisionSystem;
use PHPUnit\Framework\TestCase;

final class AdminInformationArchitectureMigrationTest extends TestCase
{
    public function testAdminInformationArchitectureMigrationIsRegisteredAfterAppearanceRevisions(): void
    {
        $classes = array_map(static fn ($migration): string => $migration::class, CoreMigrationRegistry::all());

        $theme = array_search(CreateThemeTemplateLanguageRevisionSystem::class, $classes, true);
        $admin = array_search(CreateAdminInformationArchitecture::class, $classes, true);

        self::assertIsInt($theme);
        self::assertIsInt($admin);
        self::assertGreaterThan($theme, $admin);
    }

    public function testMigrationStoresOnlyPerUserNavigationUxState(): void
    {
        $root = dirname(__DIR__, 4);
        $source = (string) file_get_contents(
            $root . '/database/migrations/core/CreateAdminInformationArchitecture.php',
        );

        self::assertStringContainsString('forwext_admin_navigation_preferences', $source);
        self::assertStringContainsString('favorites_json', $source);
        self::assertStringContainsString('recent_json', $source);
        self::assertStringContainsString('fk_forwext_admin_navigation_user', $source);
        self::assertStringNotContainsString('acp.manage', $source);
    }
}
