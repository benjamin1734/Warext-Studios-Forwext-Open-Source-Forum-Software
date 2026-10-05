<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web\Moderation;

use PHPUnit\Framework\TestCase;

final class Phase9QualificationWebSurfaceTest extends TestCase
{
    public function testLiveBrowserCoversUnifiedModerationAndOversightCaseFlow(): void
    {
        $root = dirname(__DIR__, 5);
        $live = (string) file_get_contents($root . '/tools/browser/live-route-smoke.mjs');
        $workflow = (string) file_get_contents($root . '/.github/workflows/qualification-matrix.yml');
        $fixture = (string) file_get_contents($root . '/tools/browser/seed-phase9-fixtures.php');

        foreach ([
            '/moderation',
            '/moderation/approval',
            '/moderation/audit',
            '/moderation/oversight',
            '/moderation/oversight/cases/',
        ] as $route) {
            self::assertStringContainsString($route, $live);
        }

        self::assertStringContainsString('moderation oversight case resolved', $live);
        self::assertStringContainsString('moderation oversight case mobile', $live);
        self::assertStringContainsString('seed-phase9-fixtures.php', $workflow);
        self::assertStringContainsString('forwext_moderation_oversight_review_cases', $fixture);
        self::assertStringContainsString('DatabaseModerationOversightStore', $fixture);
        self::assertStringContainsString('phase9.browser.fixture', $fixture);
    }

    public function testReviewerPoolAndCaseDetailArePermissionBacked(): void
    {
        $root = dirname(__DIR__, 5);
        $directory = (string) file_get_contents(
            $root . '/core/Moderation/Oversight/DatabaseOversightReviewerDirectory.php',
        );
        $service = (string) file_get_contents(
            $root . '/core/Moderation/Oversight/ModerationOversightService.php',
        );
        $factory = (string) file_get_contents(
            $root . '/app/Web/Moderation/ModerationApplicationFactory.php',
        );

        self::assertStringContainsString("private const PERMISSION = 'audit.review'", $directory);
        self::assertStringContainsString('authorizer->allows', $directory);
        self::assertStringContainsString("require(PermissionKey::fromString('audit.view'))", $service);
        self::assertStringContainsString('function caseDetail', $service);
        self::assertStringContainsString('viewCase($matches[1])', $factory);

        $oversight = (string) file_get_contents(
            $root . '/app/Web/Moderation/OversightHtml.php',
        );
        $caseDetail = strstr($oversight, 'public static function caseDetail');
        self::assertIsString($caseDetail);
        self::assertStringContainsString('/assets/moderation-workspace.js', $caseDetail);
        self::assertStringContainsString('data-moderation-form', $caseDetail);

        $css = (string) file_get_contents($root . '/public/assets/site-pages.css');
        self::assertStringContainsString(
            '.moderation-nav{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));overflow:visible}',
            $css,
        );
        self::assertStringContainsString('white-space:normal;text-align:center', $css);
    }
}
