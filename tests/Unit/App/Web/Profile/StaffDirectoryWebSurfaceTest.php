<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web\Profile;

use PHPUnit\Framework\TestCase;

final class StaffDirectoryWebSurfaceTest extends TestCase
{
    public function testStaffDirectoryIsFirstClassMembersSurface(): void
    {
        $root = dirname(__DIR__, 5);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');
        $handler = (string) file_get_contents($root . '/app/Web/Profile/StaffDirectoryHandler.php');
        $profile = (string) file_get_contents($root . '/app/Web/Profile/ProfileHtml.php');
        $navigation = (string) file_get_contents($root . '/core/Ui/Navigation/NavigationRegistry.php');
        $css = (string) file_get_contents($root . '/public/assets/site-pages.css');

        self::assertStringContainsString("new PathTemplate('/members/staff')", $factory);
        self::assertStringContainsString("'members.staff'", $factory);
        self::assertStringContainsString("'members.staff', 'Yetkililer', '/members/staff'", $navigation);
        self::assertStringContainsString("isset(\$visibleNavigation['members.staff'])", $profile);
        self::assertStringContainsString("'/members/staff'", $profile);

        self::assertStringContainsString('$this->directory->staffPublic(', $handler);
        self::assertStringContainsString('$this->directory->countStaffPublic()', $handler);
        self::assertStringContainsString('member-directory-card is-staff', $handler);
        self::assertStringContainsString('member-staff-badge', $handler);
        self::assertStringContainsString('Herkese açık profili bulunan yetkili üyeler', $handler);

        self::assertStringContainsString('.member-directory-card.is-staff', $css);
        self::assertStringContainsString('.member-staff-badge', $css);
        self::assertStringContainsString('.member-directory-head-actions', $css);
    }
}
