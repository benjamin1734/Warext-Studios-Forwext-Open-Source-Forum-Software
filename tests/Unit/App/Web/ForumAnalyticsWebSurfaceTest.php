<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class ForumAnalyticsWebSurfaceTest extends TestCase
{
    public function testSiteAnalyticsDashboardIsPermissionAwareAndWired(): void
    {
        $root = dirname(__DIR__,4);
        $factory = (string) file_get_contents($root.'/app/Web/WebApplicationFactory.php');
        $service = (string) file_get_contents($root.'/core/Analytics/Dashboard/ForumAnalyticsService.php');
        $handler = (string) file_get_contents($root.'/app/Web/Analytics/ForumAnalyticsHandler.php');

        self::assertStringContainsString("'analytics.dashboard'", $factory);
        self::assertStringContainsString("'/admin/analytics'", $factory);
        self::assertStringContainsString("$this->access->require($actor, 'analytics.view_forum')", $service);
        self::assertStringContainsString("['7','30','90']", $handler);
        self::assertStringContainsString("'private, no-store'", $handler);
        self::assertStringContainsString("'noindex,nofollow'", $handler);
    }

    public function testDashboardQueriesSeparateAuthoritativeCountsFromPseudonymousActivity(): void
    {
        $root = dirname(__DIR__,4);
        $repository = (string) file_get_contents(
            $root.'/core/Analytics/Dashboard/DatabaseForumAnalyticsRepository.php'
        );

        self::assertStringContainsString('FROM forwext_users', $repository);
        self::assertStringContainsString('FROM forwext_threads', $repository);
        self::assertStringContainsString('FROM forwext_posts p', $repository);
        self::assertStringContainsString("event_key='user.active'", $repository);
        self::assertStringContainsString('COUNT(DISTINCT actor_hash)', $repository);
        self::assertStringNotContainsString('email', $repository);
        self::assertStringNotContainsString('ip_address', $repository);
    }

    public function testHeartbeatUsesTheSamePrivacyAwareAnalyticsRecorder(): void
    {
        $root = dirname(__DIR__,4);
        $handler = (string) file_get_contents($root.'/app/Web/Community/PresenceHeartbeatHandler.php');
        $factory = (string) file_get_contents($root.'/app/Web/Community/CommunityApplicationFactory.php');

        self::assertStringContainsString("'user.active'", $handler);
        self::assertStringContainsString('recordBestEffort(new AnalyticsEvent(', $handler);
        self::assertStringContainsString("'forwext.analytics.privacy.v1'", $factory);
        self::assertStringContainsString('new DatabaseAnalyticsRepository($this->database())', $factory);
    }
}
