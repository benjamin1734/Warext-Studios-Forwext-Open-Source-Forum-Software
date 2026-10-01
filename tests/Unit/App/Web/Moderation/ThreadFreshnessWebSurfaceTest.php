<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web\Moderation;

use PHPUnit\Framework\TestCase;

final class ThreadFreshnessWebSurfaceTest extends TestCase
{
    public function testFreshnessPolicyAndReviewUseSharedModerationSurfaces(): void
    {
        $root=dirname(__DIR__,5);
        $policy=(string)file_get_contents($root.'/app/Web/Moderation/ThreadFreshnessPolicyHandler.php');
        $review=(string)file_get_contents($root.'/app/Web/Moderation/ThreadFreshnessReviewHandler.php');
        $css=(string)file_get_contents($root.'/public/assets/site-components.css')
            .(string)file_get_contents($root.'/public/assets/site-pages.css');

        self::assertStringContainsString('moderation-subpage discovery-page',$policy);
        self::assertStringContainsString('surface-panel freshness-policy-panel',$policy);
        self::assertStringContainsString('class="freshness-forum-row"',$policy);
        self::assertStringContainsString('moderation-subpage discovery-page',$review);
        self::assertStringContainsString('class="freshness-review-row"',$review);
        self::assertStringContainsString('class="freshness-review-actions"',$review);
        self::assertStringContainsString('.moderation-audit-snapshots',$css);
        self::assertStringContainsString('.freshness-maintenance',$css);
    }
}
