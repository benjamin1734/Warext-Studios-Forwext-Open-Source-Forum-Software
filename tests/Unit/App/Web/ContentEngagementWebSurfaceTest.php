<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class ContentEngagementWebSurfaceTest extends TestCase
{
    public function testContentDashboardAndSearchAnalyticsAreWired():void
    {
        $root=dirname(__DIR__,4);
        $factory=(string)file_get_contents($root.'/app/Web/WebApplicationFactory.php');
        $handler=(string)file_get_contents($root.'/app/Web/Analytics/ContentEngagementHandler.php');
        $service=(string)file_get_contents($root.'/core/Analytics/Engagement/ContentEngagementService.php');
        $search=(string)file_get_contents($root.'/app/Web/Search/SearchHandler.php');

        self::assertStringContainsString("'analytics.content'",$factory);
        self::assertStringContainsString("'/admin/analytics/content'",$factory);
        self::assertStringContainsString('new SearchAnalyticsService(',$factory);
        self::assertStringContainsString("'forwext.analytics.search-term.v1'",$factory);
        self::assertStringContainsString('$this->access->require($actor, \'analytics.view_content\')',$service);
        self::assertStringContainsString("['7','30','90']",$handler);
        self::assertStringContainsString("'private, no-store'",$handler);
        self::assertStringContainsString('recordBestEffort(',$search);
    }

    public function testEngagementQueriesUseStructuralOrAggregateDataNotRawUserProfiles():void
    {
        $root=dirname(__DIR__,4);
        $repository=(string)file_get_contents(
            $root.'/core/Analytics/Engagement/DatabaseContentEngagementRepository.php'
        );
        $migration=(string)file_get_contents(
            $root.'/database/migrations/core/CreateContentEngagementAnalytics.php'
        );

        self::assertStringContainsString("event_key='content.thread.view'",$repository);
        self::assertStringContainsString("content_type='thread'",$repository);
        self::assertStringContainsString('forwext_post_reactions',$repository);
        self::assertStringContainsString('forwext_post_bookmarks',$repository);
        self::assertStringContainsString('forwext_watched_threads',$repository);
        self::assertStringContainsString('forwext_watched_forums',$repository);
        self::assertStringContainsString('forwext_user_follows',$repository);
        self::assertStringContainsString('forwext_search_term_analytics',$repository);

        foreach(['email','ip_address','user_agent','request_uri'] as $forbidden){
            self::assertStringNotContainsString($forbidden,$repository);
        }

        self::assertStringContainsString('content_type',$migration);
        self::assertStringContainsString('content_id',$migration);
        self::assertStringContainsString('forwext_search_term_analytics',$migration);
    }

    public function testThreadViewProducerUsesStructuralThreadReference():void
    {
        $root=dirname(__DIR__,4);
        $middleware=(string)file_get_contents($root.'/app/Web/Analytics/AnalyticsRequestMiddleware.php');
        $registry=(string)file_get_contents($root.'/core/Analytics/AnalyticsEventRegistry.php');

        self::assertStringContainsString("'content.thread.view'",$middleware);
        self::assertStringContainsString("contentType:'thread'",$middleware);
        self::assertStringContainsString('contentId:$threadId',$middleware);
        self::assertStringContainsString("'content.thread.view'",$registry);
    }
}
