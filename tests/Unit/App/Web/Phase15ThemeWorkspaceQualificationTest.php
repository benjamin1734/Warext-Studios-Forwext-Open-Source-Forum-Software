<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use Forwext\Core\Ui\Theme\ThemePayload;
use PHPUnit\Framework\TestCase;

final class Phase15ThemeWorkspaceQualificationTest extends TestCase
{
    public function testDenseThemeWorkspaceKeepsFirstPartyRevisionModel(): void
    {
        $root = dirname(__DIR__, 4);
        $html = (string) file_get_contents($root . '/app/Web/Appearance/ThemeManageHtml.php');
        $handler = (string) file_get_contents($root . '/app/Web/Appearance/ThemeManageHandler.php');
        $service = (string) file_get_contents($root . '/core/Ui/Theme/ThemeService.php');
        $payload = (string) file_get_contents($root . '/core/Ui/Theme/ThemePayload.php');
        $migration = (string) file_get_contents($root . '/database/migrations/core/GrantAppearanceAdministrationPermissions.php');
        $registry = (string) file_get_contents($root . '/core/Install/CoreMigrationRegistry.php');
        $css = (string) file_get_contents($root . '/public/assets/admin.css');

        foreach ([
            'theme-admin--dense',
            'theme-overview',
            'theme-context-grid',
            'theme-workspace-grid',
            'theme-editor-group',
            'theme-code-grid',
            'theme-revision-head',
        ] as $marker) {
            self::assertStringContainsString($marker, $html);
            self::assertStringContainsString($marker, $css);
        }

        self::assertStringContainsString("private const MANAGE_PERMISSION = 'appearance.manage'", $service);
        self::assertStringContainsString("private const ADVANCED_PERMISSION = 'appearance.advanced'", $service);
        self::assertStringContainsString('if ($action === \'stage\')', $handler);
        self::assertStringContainsString('if ($action === \'publish\')', $handler);
        self::assertStringContainsString('if ($action === \'rollback\')', $handler);
        self::assertStringContainsString('public array $templates', $payload);
        self::assertStringContainsString('public array $phrases', $payload);
        self::assertStringContainsString('Theme cannot contain more than 32 languages.', $payload);
        self::assertStringContainsString("'appearance.manage'", $migration);
        self::assertStringContainsString("'appearance.advanced'", $migration);
        self::assertStringContainsString("\$templateKey === 'administrator' ? 'allow' : 'deny'", $migration);
        self::assertStringContainsString('new GrantAppearanceAdministrationPermissions()', $registry);
    }

    public function testEmptyThemeMapsSurviveJsonRoundTrip(): void
    {
        $payload = new ThemePayload([], []);
        $encoded = json_encode($payload->toArray(), JSON_THROW_ON_ERROR);
        $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        $restored = ThemePayload::fromArray($decoded);
        self::assertSame([], $restored->templates);
        self::assertSame([], $restored->phrases);
    }

    public function testLiveBrowserCoversThemeAndLayoutAppearanceRoutes(): void
    {
        $root = dirname(__DIR__, 4);
        $live = (string) file_get_contents($root . '/tools/browser/live-route-smoke.mjs');

        self::assertStringContainsString('/admin/appearance/themes?theme=forwext-balanced', $live);
        self::assertStringContainsString('admin theme: dense workspace contract failed', $live);
        self::assertStringContainsString('admin theme mobile: responsive contract failed', $live);
        self::assertStringContainsString('/admin/appearance/layout', $live);
        self::assertStringContainsString('admin layout: route did not return HTTP 200', $live);
    }
}
