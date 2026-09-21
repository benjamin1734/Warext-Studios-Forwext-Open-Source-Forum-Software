<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class SubscriptionWebSurfaceTest extends TestCase
{
    public function testSubscriptionRoutesUseCsrfExceptVerifiedWebhook():void
    {
        $root=dirname(__DIR__,4);
        $factory=(string)file_get_contents($root.'/app/Web/WebApplicationFactory.php');

        self::assertStringContainsString("'account.upgrades'",$factory);
        self::assertStringContainsString("'/account/upgrades'",$factory);
        self::assertStringContainsString("'account.upgrades.purchase'",$factory);
        self::assertStringContainsString("'/account/upgrades/{planId}/purchase'",$factory);
        self::assertStringContainsString("'subscription.manage'",$factory);
        self::assertStringContainsString("'/admin/subscriptions'",$factory);

        $account=$this->routeBlock($factory,"'account.upgrades'");
        $purchase=$this->routeBlock($factory,"'account.upgrades.purchase'");
        $manage=$this->routeBlock($factory,"'subscription.manage'");
        $webhook=$this->routeBlock($factory,"'subscription.webhook'");

        self::assertStringContainsString('[$subscriptionCsrf]',$account);
        self::assertStringContainsString('[$subscriptionCsrf]',$purchase);
        self::assertStringContainsString('[$subscriptionCsrf]',$manage);
        self::assertStringNotContainsString('$subscriptionCsrf',$webhook);
    }

    public function testSubscriptionWebhookUsesRawBodyAndProviderVerificationBoundary():void
    {
        $root=dirname(__DIR__,4);
        $handler=(string)file_get_contents($root.'/app/Web/Subscription/SubscriptionWebhookHandler.php');
        $service=(string)file_get_contents($root.'/core/Subscription/SubscriptionService.php');

        self::assertStringContainsString('$request->rawBody()',$handler);
        self::assertStringContainsString('$request->headers()->all()',$handler);
        self::assertStringContainsString('$provider->verifyWebhook($request)',$service);
        self::assertStringContainsString('hash(\'sha256\',$request->rawBody)', $service);
    }

    public function testAccountAndAdminPagesAreIncludedInNativeComposition():void
    {
        $root=dirname(__DIR__,4);
        $factory=(string)file_get_contents($root.'/app/Web/WebApplicationFactory.php');
        $smoke=(string)file_get_contents($root.'/tools/quality/smoke-post-install-web.php');

        self::assertStringContainsString('new SubscriptionAccountHandler(',$factory);
        self::assertStringContainsString('new SubscriptionManageHandler(',$factory);
        self::assertStringContainsString("'/account/upgrades'",$smoke);
    }

    private function routeBlock(string $factory,string $needle):string
    {
        $start=strpos($factory,$needle);
        self::assertNotFalse($start);
        $end=strpos($factory,']));',$start);
        if($end===false)$end=strpos($factory,'));',$start);
        self::assertNotFalse($end);
        return substr($factory,$start,$end-$start+4);
    }
}
