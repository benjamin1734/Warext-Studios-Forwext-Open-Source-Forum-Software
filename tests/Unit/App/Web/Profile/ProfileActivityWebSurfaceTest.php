<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web\Profile;

use Forwext\App\Web\Profile\ActivityFeedHandler;
use Forwext\App\Web\Profile\ProfileActivityCsrfTokenHandler;
use Forwext\App\Web\Profile\ProfileActivityDeleteHandler;
use Forwext\App\Web\Profile\ProfileActivitySettingsHandler;
use Forwext\App\Web\Profile\ProfileCommentsHandler;
use Forwext\App\Web\Profile\ProfilePostReactionHandler;
use Forwext\App\Web\Profile\ProfilePostsHandler;
use PHPUnit\Framework\TestCase;

final class ProfileActivityWebSurfaceTest extends TestCase
{
    public function testNativeProfileActivityHandlersAreAutoloadable(): void
    {
        self::assertTrue(class_exists(ProfilePostsHandler::class));
        self::assertTrue(class_exists(ProfileCommentsHandler::class));
        self::assertTrue(class_exists(ProfilePostReactionHandler::class));
        self::assertTrue(class_exists(ProfileActivityDeleteHandler::class));
        self::assertTrue(class_exists(ProfileActivitySettingsHandler::class));
        self::assertTrue(class_exists(ProfileActivityCsrfTokenHandler::class));
        self::assertTrue(class_exists(ActivityFeedHandler::class));
    }
    public function testDiscoverySurfacesUseSharedResponsiveShell(): void
    {
        $root = dirname(__DIR__, 5);
        $activity = (string) file_get_contents($root . '/app/Web/Profile/ActivityFeedHtml.php');
        $members = (string) file_get_contents($root . '/app/Web/Profile/MemberDirectoryHandler.php');
        $online = (string) file_get_contents($root . '/app/Web/Community/OnlineUsersHandler.php');

        self::assertStringContainsString('surface-head activity-feed-head', $activity);
        self::assertStringContainsString("string \$title = 'Neler yeni?'", $activity);
        self::assertStringContainsString("'<div><h1>' . self::e(\$title) . '</h1>'", $activity);
        self::assertStringNotContainsString('forum-eyebrow">TOPLULUK', $activity . $members);
        self::assertStringContainsString('surface-pagination activity-pagination', $activity);
        self::assertStringContainsString('$routePath . \'?page=\'', $activity);
        self::assertStringContainsString('surface-head member-directory-head', $members);
        self::assertStringContainsString('member-directory-grid', $members);
        self::assertStringContainsString('surface-head online-users-head', $online);
        self::assertStringNotContainsString('style="margin-top:', $members . $online);
        $settings = (string) file_get_contents($root . '/app/Web/Profile/ProfileActivitySettingsHtml.php');
        self::assertStringContainsString('surface-head profile-activity-head', $settings);
        self::assertStringContainsString('profile-activity-form', $settings);

        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');
        $profile = (string) file_get_contents($root . '/app/Web/Profile/ProfileHtml.php');
        self::assertStringContainsString("new PathTemplate('/activity/profile-posts')", $factory);
        self::assertStringContainsString('[ActivityFeedType::ProfilePostCreated]', $factory);
        self::assertStringContainsString("'Yeni profil gönderileri'", $factory);
        self::assertStringContainsString("'/activity/profile-posts'", $profile);
    }

}
