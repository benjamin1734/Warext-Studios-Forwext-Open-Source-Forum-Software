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
        $css = (string) file_get_contents($root . '/public/assets/site-shell.css');

        self::assertStringContainsString('moderation-workspace discovery-page', $html);
        self::assertStringContainsString('surface-head moderation-head', $html);
        self::assertStringContainsString('class="moderation-stat"', $html);
        self::assertStringContainsString('class="moderation-row"', $html);
        self::assertStringContainsString('data-moderation-form', $html);
        self::assertStringContainsString('.moderation-stats', $css);
        self::assertStringContainsString('.moderation-row-action', $css);
    }
}
