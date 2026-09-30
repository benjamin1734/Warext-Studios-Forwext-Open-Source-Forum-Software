<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Ui;

use Forwext\Core\Ui\DesignToken\DesignTokenCatalog;
use PHPUnit\Framework\TestCase;

final class AccessibilityContrastTokenTest extends TestCase
{
    public function testCoreTextAndFocusTokensMeetWcagAaContrastOnMaintainedSurfaces(): void
    {
        $tokens = DesignTokenCatalog::coreDefaults();

        foreach ([
            ['semantic.text.primary', 'semantic.surface.primary', 4.5],
            ['semantic.text.primary', 'semantic.surface.secondary', 4.5],
            ['semantic.text.muted', 'semantic.surface.primary', 4.5],
            ['semantic.text.muted', 'semantic.surface.secondary', 4.5],
            ['semantic.accent.primary', 'semantic.surface.primary', 4.5],
            ['semantic.accent.contrast', 'semantic.accent.primary', 4.5],
        ] as [$foreground, $background, $minimum]) {
            $ratio = self::contrastRatio(
                $tokens->resolveValue($foreground),
                $tokens->resolveValue($background),
            );

            self::assertGreaterThanOrEqual(
                $minimum,
                $ratio,
                sprintf('%s on %s has insufficient contrast: %.2f:1', $foreground, $background, $ratio),
            );
        }
    }

    private static function contrastRatio(string $foreground, string $background): float
    {
        $foregroundLuminance = self::luminance($foreground);
        $backgroundLuminance = self::luminance($background);

        return (max($foregroundLuminance, $backgroundLuminance) + 0.05)
            / (min($foregroundLuminance, $backgroundLuminance) + 0.05);
    }

    private static function luminance(string $hex): float
    {
        self::assertMatchesRegularExpression('/^#[0-9a-fA-F]{6}$/D', $hex);

        $channels = [
            hexdec(substr($hex, 1, 2)) / 255,
            hexdec(substr($hex, 3, 2)) / 255,
            hexdec(substr($hex, 5, 2)) / 255,
        ];
        foreach ($channels as &$channel) {
            $channel = $channel <= 0.04045
                ? $channel / 12.92
                : (($channel + 0.055) / 1.055) ** 2.4;
        }
        unset($channel);

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
