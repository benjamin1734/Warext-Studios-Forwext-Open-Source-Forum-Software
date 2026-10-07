<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class Phase19ResponsiveAccessibilityQualificationTest extends TestCase
{
    public function testLiveBrowserRunsMultiViewportHighPriorityCorpus(): void
    {
        $root = dirname(__DIR__, 4);
        $live = (string) file_get_contents($root . '/tools/browser/live-route-smoke.mjs');

        foreach (['390', '768', '1024'] as $viewport) {
            self::assertStringContainsString('label: "' . $viewport . '"', $live);
        }

        foreach ([
            'account-preferences',
            'servers',
            'moderation-audit',
            'admin-users',
            'admin-themes',
            'admin-navigation',
            'admin-analytics',
            'admin-system-operations',
        ] as $label) {
            self::assertStringContainsString('["' . $label . '",', $live);
        }

        self::assertStringContainsString('duplicate DOM ids', $live);
        self::assertStringContainsString('phase19 focus audit', $live);
        self::assertStringContainsString('phase19 route corpus', $live);
    }

    public function testNewAcpNavigationFamiliesHaveFocusAndCoarseTargetContracts(): void
    {
        $root = dirname(__DIR__, 4);
        $css = (string) file_get_contents($root . '/public/assets/admin.css');

        foreach ([
            '.platform-tab',
            '.analytics-tabs a',
            '.analytics-range a',
            '.ops-section-tabs a',
        ] as $selector) {
            self::assertStringContainsString($selector, $css);
        }

        self::assertStringContainsString('@media(pointer:coarse)', $css);
        self::assertStringContainsString(':focus-visible', $css);
        self::assertStringContainsString('min-height:44px', $css);
    }
}
