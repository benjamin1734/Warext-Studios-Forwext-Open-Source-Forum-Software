<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class BackgroundAppearanceWebSurfaceTest extends TestCase
{
    public function testNativeShellCompilesSiteAndHeaderBackgroundScopes(): void
    {
        $root = dirname(__DIR__, 4);
        $html = (string) file_get_contents($root . '/app/Web/Profile/ProfileHtml.php');

        self::assertStringContainsString('BackgroundRegistry::coreDefaults($catalog)', $html);
        self::assertStringContainsString('new BackgroundCssCompiler()', $html);
        self::assertStringContainsString('data-forwext-background-scope="site"', $html);
        self::assertStringContainsString('data-forwext-background-scope="header"', $html);
    }

    public function testProfileSurfaceExposesOpaqueProfileScopeId(): void
    {
        $root = dirname(__DIR__, 4);
        $handler = (string) file_get_contents($root . '/app/Web/Profile/ProfileViewHandler.php');

        self::assertStringContainsString('data-forwext-background-scope="profile"', $handler);
        self::assertStringContainsString('$profile->userId->value()', $handler);
        self::assertStringContainsString('ProfileHtml::escape(', $handler);
    }

    public function testOptionalModernFrontendSharesBackgroundManifest(): void
    {
        $root = dirname(__DIR__, 4);
        $typescript = (string) file_get_contents($root . '/packages/design-tokens/index.ts');

        self::assertStringContainsString(
            '../../resources/appearance/forwext-backgrounds-default.json',
            $typescript,
        );
        self::assertStringContainsString('forwextBackgroundManifest', $typescript);
    }
}
