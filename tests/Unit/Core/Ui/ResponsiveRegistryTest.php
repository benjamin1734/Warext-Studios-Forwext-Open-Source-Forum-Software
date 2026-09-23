<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Ui;

use Forwext\Core\Ui\Responsive\ResponsiveRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ResponsiveRegistryTest extends TestCase
{
    public function testCoreManifestDefinesNonOverlappingMobileTabletDesktopBreakpoints(): void
    {
        $registry = ResponsiveRegistry::coreDefaults();
        $breakpoints = $registry->breakpoints();

        self::assertSame(['mobile', 'tablet', 'desktop'], array_map(
            static fn ($breakpoint): string => $breakpoint->key,
            $breakpoints,
        ));
        self::assertSame(767, $registry->breakpoint('mobile')->maxWidth);
        self::assertSame(768, $registry->breakpoint('tablet')->minWidth);
        self::assertSame(1024, $registry->breakpoint('desktop')->minWidth);
        self::assertNotEmpty($registry->rules());
    }

    public function testOverlappingBreakpointsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ResponsiveRegistry::fromJson((string) json_encode([
            'version' => 1,
            'breakpoints' => [
                ['key' => 'mobile', 'min_width' => 0, 'max_width' => 800],
                ['key' => 'tablet', 'min_width' => 700, 'max_width' => 1000],
                ['key' => 'desktop', 'min_width' => 1001, 'max_width' => null],
            ],
            'rules' => [],
        ], JSON_THROW_ON_ERROR));
    }

    public function testVisibilityFontSpacingAndLayoutBoundsAreValidated(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ResponsiveRegistry::fromJson((string) json_encode([
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
                'font_scale' => 20,
                'spacing_scale' => 100,
                'layout' => 'stack',
            ]],
        ], JSON_THROW_ON_ERROR));
    }
}
