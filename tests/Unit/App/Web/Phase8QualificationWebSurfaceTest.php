<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class Phase8QualificationWebSurfaceTest extends TestCase
{
    public function testPhase8DensitySurfacesAreWiredIntoRealBrowserQualification(): void
    {
        $root = dirname(__DIR__, 4);
        $live = (string) file_get_contents($root . '/tools/browser/live-route-smoke.mjs');
        $seeder = (string) file_get_contents($root . '/tools/browser/seed-phase8-fixtures.php');
        $qualification = (string) file_get_contents($root . '/.github/workflows/qualification-matrix.yml');
        $database = (string) file_get_contents($root . '/.github/workflows/mysql-migration-smoke.yml');

        foreach ([
            '/portfolio?category=general&featured=1',
            '/portfolio/eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',
            '/portfolio/manage?project=eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',
            '/faq?lang=tr',
            '/faq/tr/phase8-browser-faq',
            '/account/referrals',
            '/referrals/manage',
            '/bugs',
            '/bugs/abababababababababababababababab',
            '/bugs/report',
        ] as $route) {
            self::assertStringContainsString($route, $live);
        }

        foreach ([
            'portfolio-browse-bar',
            'portfolio-project-main-grid',
            'portfolio-manage-grid',
            'faq-overview',
            'faq-article-grid',
            'referral-manage-page',
            'referral-qualification-panel',
            'bug-list-overview',
            'bug-detail-primary-grid',
            'bug-form-grid',
        ] as $selector) {
            self::assertStringContainsString($selector, $live);
        }

        self::assertStringContainsString("forwext_portfolio_projects", $seeder);
        self::assertStringContainsString("forwext_faq_articles", $seeder);
        self::assertStringContainsString("forwext_bug_reports", $seeder);
        self::assertStringContainsString("phase8-browser-project", $seeder);
        self::assertStringContainsString("phase8-browser-faq", $seeder);
        self::assertStringContainsString("seed-phase8-fixtures.php", $qualification);
        self::assertStringContainsString("pull_request:", $qualification);
        self::assertStringContainsString("pull_request:", $database);
    }

    public function testPhase8BrowserAcceptanceCoversDesktopAndMobileStates(): void
    {
        $root = dirname(__DIR__, 4);
        $live = (string) file_get_contents($root . '/tools/browser/live-route-smoke.mjs');

        self::assertStringContainsString('portfolio: browse density contract failed', $live);
        self::assertStringContainsString('faq article: density contract failed', $live);
        self::assertStringContainsString('referrals manage: density contract failed', $live);
        self::assertStringContainsString('bugs: list density contract failed', $live);
        self::assertStringContainsString('portfolio mobile: browse responsive contract failed', $live);
        self::assertStringContainsString('portfolio detail mobile: responsive contract failed', $live);
        self::assertStringContainsString('portfolio manage mobile: responsive contract failed', $live);
        self::assertStringContainsString('faq mobile: responsive contract failed', $live);
        self::assertStringContainsString('referrals manage mobile: responsive contract failed', $live);
        self::assertStringContainsString('bugs mobile: list responsive contract failed', $live);
        self::assertStringContainsString('bug report form mobile: responsive contract failed', $live);
    }
}
