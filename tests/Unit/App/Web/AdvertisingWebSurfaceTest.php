<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class AdvertisingWebSurfaceTest extends TestCase
{
    public function testAdvertisingRoutesAndMiddlewareAreWired():void
    {
        $root=dirname(__DIR__,4);
        $factory=(string)file_get_contents($root.'/app/Web/WebApplicationFactory.php');

        self::assertStringContainsString("'advertising.click'",$factory);
        self::assertStringContainsString("'/ads/click/{campaignId}'",$factory);
        self::assertStringContainsString("'advertising.manage'",$factory);
        self::assertStringContainsString("'/admin/advertising'",$factory);
        self::assertStringContainsString('new AdvertisingMiddleware(',$factory);
        self::assertStringContainsString(
            '[$easterEggMiddleware, $advertisingMiddleware, $analyticsMiddleware]',
            $factory
        );

        $manage=$this->routeBlock($factory,"'advertising.manage'");
        $click=$this->routeBlock($factory,"'advertising.click'");
        self::assertStringContainsString('[$advertisingCsrf]',$manage);
        self::assertStringNotContainsString('$advertisingCsrf',$click);
    }

    public function testRendererUsesTrackedClickAndEscapedCreativeCopy():void
    {
        $root=dirname(__DIR__,4);
        $renderer=(string)file_get_contents($root.'/app/Web/Advertising/AdvertisingRenderer.php');
        self::assertStringContainsString("'/ads/click/'",$renderer);
        self::assertStringContainsString('ProfileHtml::escape($campaign->headline)',$renderer);
        self::assertStringContainsString('ProfileHtml::escape($campaign->body)',$renderer);
        self::assertStringNotContainsString('$campaign->destinationUrl)', $renderer);
    }

    public function testRuntimeSupportsAllRequiredTargetingDimensions():void
    {
        $root=dirname(__DIR__,4);
        $service=(string)file_get_contents($root.'/core/Advertising/AdvertisingService.php');
        $middleware=(string)file_get_contents($root.'/app/Web/Advertising/AdvertisingMiddleware.php');

        self::assertStringContainsString('routeTargets',$service);
        self::assertStringContainsString('forumTargets',$service);
        self::assertStringContainsString('groupTargets',$service);
        self::assertStringContainsString('deviceTargets',$service);
        self::assertStringContainsString('frequencyWindowSeconds',$service);
        self::assertStringContainsString('impressionCount',$service);
        self::assertStringContainsString('$this->threads->find',$middleware);
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
