<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class AnalyticsWebSurfaceTest extends TestCase
{
    public function testPrivacyAwareAnalyticsMiddlewareIsWiredGlobally():void
    {
        $root=dirname(__DIR__,4);
        $factory=(string)file_get_contents($root.'/app/Web/WebApplicationFactory.php');

        self::assertStringContainsString('new AnalyticsEventRecorder(',$factory);
        self::assertStringContainsString('AnalyticsEventRegistry::withCoreDefaults()',$factory);
        self::assertStringContainsString('new AnalyticsPrivacyHasher($this->analyticsPrivacyKey($config))',$factory);
        self::assertStringContainsString('new AnalyticsRequestMiddleware(',$factory);
        self::assertStringContainsString('$firstPartyModuleRouteMiddleware', $factory);
        self::assertStringContainsString('$moduleAnalyticsMiddleware', $factory);
        self::assertStringContainsString(
            "new FirstPartyModuleConditionalMiddleware(
            'analytics'",
            $factory
        );
        self::assertStringContainsString("'forwext.analytics.privacy.v1'",$factory);
    }

    public function testRequestMiddlewareUsesBestEffortCollectionOnlyForSuccessfulHtmlGets():void
    {
        $root=dirname(__DIR__,4);
        $middleware=(string)file_get_contents($root.'/app/Web/Analytics/AnalyticsRequestMiddleware.php');

        self::assertStringContainsString("request->method()->value!=='GET'",$middleware);
        self::assertStringContainsString("response->status()!==200",$middleware);
        self::assertStringContainsString("str_starts_with(\$type,'text/html')", $middleware);
        self::assertStringContainsString("recordBestEffort(new AnalyticsEvent(",$middleware);
        self::assertStringContainsString("'user.active'",$middleware);
        self::assertStringContainsString("'forum.view'",$middleware);
    }

    public function testAnalyticsSchemaDoesNotPersistRawPersonalRequestIdentifiers():void
    {
        $root=dirname(__DIR__,4);
        $migration=(string)file_get_contents($root.'/database/migrations/core/CreateAnalyticsEventModel.php');
        $repository=(string)file_get_contents($root.'/core/Analytics/DatabaseAnalyticsRepository.php');

        foreach(['ip_address','user_agent','email_address','request_uri','query_string','raw_user_id'] as $forbidden){
            self::assertStringNotContainsString($forbidden,$migration);
            self::assertStringNotContainsString($forbidden,$repository);
        }

        self::assertStringContainsString('actor_hash',$migration);
        self::assertStringContainsString('session_hash',$migration);
        self::assertStringContainsString('subject_hash',$migration);
    }
}
