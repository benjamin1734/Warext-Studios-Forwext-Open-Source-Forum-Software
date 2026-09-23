<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Ui;

use Forwext\Core\Routing\BasePath;
use Forwext\Core\Ui\Appearance\Background\BackgroundCssCompiler;
use Forwext\Core\Ui\Appearance\Background\BackgroundRegistry;
use Forwext\Core\Ui\DesignToken\DesignTokenCatalog;
use PHPUnit\Framework\TestCase;

final class BackgroundCssCompilerTest extends TestCase
{
    public function testCompilerSupportsAnimatedGradientAndReducedMotion(): void
    {
        $tokens = DesignTokenCatalog::coreDefaults();
        $registry = BackgroundRegistry::fromJson((string) json_encode([
            'version' => 1,
            'backgrounds' => [[
                'key' => 'header.animated',
                'scope' => 'header',
                'scope_id' => null,
                'kind' => 'gradient',
                'colors' => ['semantic.page.background', 'semantic.accent.primary'],
                'asset' => null,
                'pattern' => null,
                'opacity' => 72,
                'scale' => 175,
                'rotation' => 9,
                'blend' => 'overlay',
                'animated' => true,
                'animation_duration_ms' => 9000,
            ]],
        ], JSON_THROW_ON_ERROR), $tokens);

        $css = (new BackgroundCssCompiler())->compile($registry, $tokens, new BasePath(''));

        self::assertStringContainsString('linear-gradient(135deg,', $css);
        self::assertStringContainsString('opacity:0.72', $css);
        self::assertStringContainsString('scale(1.75) rotate(9deg)', $css);
        self::assertStringContainsString('background-blend-mode:overlay', $css);
        self::assertStringContainsString('@keyframes forwext-bg-', $css);
        self::assertStringContainsString('prefers-reduced-motion: reduce', $css);
        self::assertStringContainsString('animation:none!important', $css);
    }

    public function testCompilerSupportsSafeImageAndEntityScopesWithBasePath(): void
    {
        $tokens = DesignTokenCatalog::coreDefaults();
        $profileId = str_repeat('a', 32);
        $categoryId = str_repeat('b', 32);
        $registry = BackgroundRegistry::fromJson((string) json_encode([
            'version' => 1,
            'backgrounds' => [
                [
                    'key' => 'profile.image',
                    'scope' => 'profile',
                    'scope_id' => $profileId,
                    'kind' => 'image',
                    'colors' => [],
                    'asset' => 'assets/appearance/profile/bg.webp',
                    'pattern' => null,
                ],
                [
                    'key' => 'category.pattern',
                    'scope' => 'category',
                    'scope_id' => $categoryId,
                    'kind' => 'pattern',
                    'colors' => ['semantic.accent.primary', 'semantic.page.background'],
                    'asset' => null,
                    'pattern' => 'checker',
                ],
            ],
        ], JSON_THROW_ON_ERROR), $tokens);

        $css = (new BackgroundCssCompiler())->compile($registry, $tokens, new BasePath('/forum'));

        self::assertStringContainsString(
            '[data-forwext-background-scope="profile"][data-forwext-background-id="' . $profileId . '"]',
            $css,
        );
        self::assertStringContainsString(
            '[data-forwext-background-scope="category"][data-forwext-background-id="' . $categoryId . '"]',
            $css,
        );
        self::assertStringContainsString(
            'url("/forum/assets/appearance/profile/bg.webp")',
            $css,
        );
        self::assertStringContainsString('conic-gradient(', $css);
    }
}
