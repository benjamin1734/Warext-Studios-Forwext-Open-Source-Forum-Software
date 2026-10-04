<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class ReferralAccountWebSurfaceTest extends TestCase
{
    public function testReferralAccountUsesSharedAccountSurfaceWhileManageRemainsSeparate(): void
    {
        $root=dirname(__DIR__,4);
        $html=(string)file_get_contents($root.'/app/Web/Referral/ReferralHtml.php');
        $css=(string)file_get_contents($root.'/public/assets/site-components.css')
            .(string)file_get_contents($root.'/public/assets/site-pages.css');

        self::assertStringContainsString('referral-account discovery-page',$html);
        self::assertStringContainsString('surface-head referral-head',$html);
        self::assertStringContainsString('class="referral-campaign"',$html);
        self::assertStringContainsString('class="referral-reward-row"',$html);
        self::assertStringContainsString('public static function manage',$html);
        self::assertStringContainsString('referral-manage-page discovery-page',$html);
        self::assertStringContainsString('surface-head referral-manage-head',$html);
        self::assertStringContainsString('surface-panel referral-qualification-panel',$html);
        self::assertStringContainsString('class="referral-campaign-editor"',$html);
        self::assertStringContainsString('class="referral-review-row"',$html);
        self::assertStringContainsString('class="referral-review-actions"',$html);
        self::assertStringContainsString('.referral-stats',$css);
        self::assertStringContainsString('.referral-qualification-panel',$css);
        self::assertStringContainsString('.referral-review-row',$css);
    }
}
