<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class MemberProfileDensityWebSurfaceTest extends TestCase
{
    public function testMemberDirectoriesExposeCompactResultStateAndBoundedNavigation(): void
    {
        $root = dirname(__DIR__, 4);
        $members = (string) file_get_contents($root . '/app/Web/Profile/MemberDirectoryHandler.php');
        $staff = (string) file_get_contents($root . '/app/Web/Profile/StaffDirectoryHandler.php');
        $online = (string) file_get_contents($root . '/app/Web/Community/OnlineUsersHandler.php');

        self::assertStringContainsString('class="member-directory-summary" role="status"', $members);
        self::assertStringContainsString("'Önceki'", $members);
        self::assertStringContainsString("'Sonraki'", $members);
        self::assertStringContainsString('member-pagination-gap', $members);
        self::assertStringContainsString('aria-label="Üye dizini filtreleri"', $members);

        self::assertStringContainsString('class="member-directory-summary" role="status"', $staff);
        self::assertStringContainsString('/members/online', $staff);
        self::assertStringContainsString('aria-label="Yetkili dizini filtreleri"', $staff);

        self::assertStringContainsString('number_format(count($users)', $online);
        self::assertStringContainsString('/members/staff', $online);
        self::assertStringContainsString('aria-label="Çevrimiçi görünürlük ayarı"', $online);
    }

    public function testProfileUsesOnlyExistingViewerVisibleStateForDenseSummary(): void
    {
        $root = dirname(__DIR__, 4);
        $profile = (string) file_get_contents($root . '/app/Web/Profile/ProfileViewHandler.php');

        self::assertStringContainsString('count($visibleTabs)', $profile);
        self::assertStringContainsString('class="profile-overview-facts"', $profile);
        self::assertStringContainsString('class="profile-head-actions"', $profile);
        self::assertStringContainsString('aria-label="Üye içeriği"', $profile);
        self::assertStringContainsString('aria-controls="', $profile);
        self::assertStringNotContainsString('primaryGroup', $profile);
        self::assertStringNotContainsString('displayRole', $profile);
    }

    public function testMemberAndProfileResponsiveFocusContractsAreOwnedBySharedPageStyles(): void
    {
        $root = dirname(__DIR__, 4);
        $css = (string) file_get_contents($root . '/public/assets/site-pages.css');

        self::assertStringContainsString('/* member-profile-density-v1 */', $css);
        self::assertStringContainsString('.member-directory-card:focus-visible', $css);
        self::assertStringContainsString('.member-pagination>a[aria-current="page"]', $css);
        self::assertStringContainsString('.profile-reference-shell .profile-head-actions', $css);
        self::assertStringContainsString('.profile-reference-shell .profile-overview-facts', $css);
        self::assertStringContainsString('.profile-reference-shell .profile-tabs a:focus-visible', $css);
        self::assertStringContainsString('min-height:44px', $css);
    }
}
