<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Ui;

use Forwext\Core\Ui\Responsive\ResponsiveCssCompiler;
use Forwext\Core\Ui\Responsive\ResponsiveRegistry;
use PHPUnit\Framework\TestCase;

final class ResponsiveCssCompilerTest extends TestCase
{
    public function testCompilerEmitsBreakpointsOverridesMobileNavAccessibilityAndReducedMotion(): void
    {
        $css = (new ResponsiveCssCompiler())->compile(ResponsiveRegistry::coreDefaults());

        self::assertStringContainsString(
            '@media (min-width:0px) and (max-width:767px)',
            $css,
        );
        self::assertStringContainsString('gap:12px', $css);
        self::assertStringContainsString('margin-block-start:24px', $css);
        self::assertStringContainsString('font-size:95%', $css);
        self::assertStringContainsString('flex-direction:column', $css);
        self::assertStringContainsString('data-forwext-mobile-nav="enhanced"', $css);
        self::assertStringContainsString('.skip-link:focus{transform:none}', $css);
        self::assertStringContainsString(':focus-visible{outline:2px solid', $css);
        self::assertStringContainsString('prefers-reduced-motion: reduce', $css);
        self::assertStringContainsString('[dir="rtl"] .nav{direction:rtl}', $css);
    }

    public function testCompilerSupportsVisibilityAndGridOverridesWithoutRawSelectors(): void
    {
        $registry = ResponsiveRegistry::fromJson((string) json_encode([
            'version' => 1,
            'breakpoints' => [
                ['key' => 'mobile', 'min_width' => 0, 'max_width' => 767],
                ['key' => 'tablet', 'min_width' => 768, 'max_width' => 1023],
                ['key' => 'desktop', 'min_width' => 1024, 'max_width' => null],
            ],
            'rules' => [[
                'breakpoint' => 'mobile',
                'target' => 'sidebar',
                'visibility' => 'hidden',
                'font_scale' => 100,
                'spacing_scale' => 100,
                'layout' => 'grid',
            ]],
        ], JSON_THROW_ON_ERROR));

        $css = (new ResponsiveCssCompiler())->compile($registry);

        self::assertStringContainsString(
            '[data-forwext-responsive-target="sidebar"]',
            $css,
        );
        self::assertStringContainsString('display:none!important', $css);
        self::assertStringContainsString('display:grid', $css);
    }
}
