<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class ModerationWorkspaceWebSurfaceTest extends TestCase
{
    public function testWorkspaceUsesSharedDashboardSurfaceWithoutSearchHitRows(): void
    {
        $root = dirname(__DIR__, 4);
        $html = (string) file_get_contents($root . '/app/Web/Moderation/ModerationWorkspaceHtml.php');
        $css = (string) file_get_contents($root . '/public/assets/site-components.css')
            . (string) file_get_contents($root . '/public/assets/site-pages.css');

        self::assertStringContainsString('moderation-workspace discovery-page', $html);
        self::assertStringContainsString('surface-head moderation-head', $html);
        self::assertStringContainsString('class="moderation-stat"', $html);
        self::assertStringContainsString('class="moderation-row"', $html);
        self::assertStringContainsString('data-moderation-form', $html);
        self::assertStringContainsString('.moderation-stats', $css);
        self::assertStringContainsString('.moderation-row-action', $css);
        $approval = (string) file_get_contents($root . '/app/Web/Moderation/ApprovalQueueHtml.php');
        $report = (string) file_get_contents($root . '/app/Web/Moderation/ReportModerationHtml.php');
        self::assertStringContainsString('moderation-subpage discovery-page', $approval);
        self::assertStringContainsString('class="moderation-list-row"', $approval);
        self::assertStringContainsString('moderation-subpage discovery-page', $report);
        self::assertStringContainsString('class="moderation-note"', $report);
        $abuse = (string) file_get_contents($root . '/app/Web/Moderation/AbuseHtml.php');
        $oversight = (string) file_get_contents($root . '/app/Web/Moderation/OversightHtml.php');
        $discipline = (string) file_get_contents($root . '/app/Web/Moderation/DisciplineHtml.php');
        self::assertStringContainsString('moderation-subpage discovery-page', $abuse);
        self::assertStringContainsString('class="moderation-list-row"', $abuse);
        self::assertStringContainsString('moderation-subpage discovery-page', $oversight);
        self::assertStringContainsString('class="moderation-note"', $oversight);
        self::assertStringContainsString('moderation-subpage discovery-page', $discipline);
    }
}
