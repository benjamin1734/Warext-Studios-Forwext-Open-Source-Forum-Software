<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class DesignTokenWebSurfaceTest extends TestCase
{
    public function testNativePhpFrontendUsesSharedDesignTokenCompiler(): void
    {
        $root = dirname(__DIR__, 4);
        $profileHtml = (string) file_get_contents($root . '/app/Web/Profile/ProfileHtml.php');

        self::assertStringContainsString('DesignTokenCatalog::coreDefaults()', $profileHtml);
        self::assertStringContainsString('new DesignTokenCssCompiler()', $profileHtml);
        self::assertStringContainsString(
            '--bg:var(--forwext-semantic-page-background)',
            $profileHtml,
        );
        self::assertStringContainsString(
            'var(--forwext-typography-font-family-sans)',
            $profileHtml,
        );
        self::assertStringNotContainsString('--bg:#0d1117', $profileHtml);
    }

    public function testCanonicalManifestIsSharedWithTheTypescriptBoundary(): void
    {
        $root = dirname(__DIR__, 4);
        $manifestPath = $root . '/resources/design-tokens/forwext-default.json';
        $typescript = (string) file_get_contents($root . '/packages/design-tokens/index.ts');

        $manifest = json_decode(
            (string) file_get_contents($manifestPath),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(1, $manifest['version'] ?? null);
        self::assertNotEmpty($manifest['tokens'] ?? []);
        self::assertStringContainsString(
            '../../resources/design-tokens/forwext-default.json',
            $typescript,
        );
        self::assertStringContainsString('designTokenCssVariable', $typescript);
    }
}
