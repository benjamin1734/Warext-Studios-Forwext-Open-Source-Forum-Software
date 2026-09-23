<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class ComponentAppearanceWebSurfaceTest extends TestCase
{
    public function testNativeSurfaceCompilesAndConsumesComponentAppearanceVariables(): void
    {
        $root = dirname(__DIR__, 4);
        $profile = (string) file_get_contents($root . '/app/Web/Profile/ProfileHtml.php');
        $roleCss = (string) file_get_contents($root . '/resources/css/role-appearance.css');

        self::assertStringContainsString('ComponentAppearanceRegistry::coreDefaults($catalog)', $profile);
        self::assertStringContainsString('new ComponentAppearanceCssCompiler()', $profile);
        self::assertStringContainsString('--forwext-component-header-background', $profile);
        self::assertStringContainsString('--forwext-component-input-background', $profile);
        self::assertStringContainsString('--forwext-component-badge-background', $profile);
        self::assertStringContainsString('--forwext-component-alert-background', $profile);
        self::assertStringContainsString('--forwext-component-role-banner-background', $roleCss);
    }

    public function testComponentManifestCoversBindingRoadmapTargets(): void
    {
        $root = dirname(__DIR__, 4);
        $manifest = json_decode(
            (string) file_get_contents(
                $root . '/resources/appearance/forwext-components-default.json',
            ),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $targets = array_column($manifest['components'] ?? [], 'target');

        foreach ([
            'header',
            'navigation',
            'footer',
            'forum',
            'thread',
            'post',
            'profile',
            'button',
            'input',
            'modal',
            'badge',
            'role-banner',
            'editor',
            'table',
            'alert',
        ] as $target) {
            self::assertContains($target, $targets);
        }
    }
}
