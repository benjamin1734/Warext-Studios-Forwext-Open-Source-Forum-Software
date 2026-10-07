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

    public function testHighRiskMobileNavigationAndTableFamiliesUseNonOverflowLayouts(): void
    {
        $root = dirname(__DIR__, 4);
        $adminCss = (string) file_get_contents($root . '/public/assets/admin.css');
        $siteComponentsCss = (string) file_get_contents($root . '/public/assets/site-components.css');
        $siteCss = (string) file_get_contents($root . '/public/assets/site-pages.css');

        foreach ([
            'grid-template-columns:repeat(3,minmax(0,1fr));overflow:visible',
            'grid-template-columns:repeat(4,minmax(0,1fr));',
            '.ac-user-history .ac-table{min-width:720px}' . PHP_EOL . '@media(max-width:960px){',
            '.theme-diff-scroll{overflow:visible}',
            '.theme-diff table{min-width:0;width:100%;table-layout:fixed}',
        ] as $contract) {
            self::assertStringContainsString($contract, $adminCss);
        }

        self::assertStringContainsString('grid-template-columns:repeat(3,minmax(0,1fr));', $siteComponentsCss);
        self::assertStringContainsString('.nav-secondary-group{grid-template-columns:repeat(2,minmax(0,1fr))}', $siteComponentsCss);

        foreach ([
            '.surface-tabs{display:flex;flex-wrap:wrap;overflow-x:visible}',
            '.staff-table-wrap{overflow-x:visible}',
            '.staff-filter-form{margin:0;padding:12px 13px}' . PHP_EOL . '@media(max-width:820px){',
            '.portfolio-filter-tabs{flex-wrap:wrap;overflow-x:visible}',
            '.faq-tabs{flex-wrap:wrap;overflow-x:visible}',
            '.moderation-nav{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));overflow:visible}',
        ] as $contract) {
            self::assertStringContainsString($contract, $siteCss);
        }
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
