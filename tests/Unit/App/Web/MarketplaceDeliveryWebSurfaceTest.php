<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class MarketplaceDeliveryWebSurfaceTest extends TestCase
{
    public function testDeliveryRoutesAreRegisteredAndHumanMutationsUseMarketplaceCsrf():void
    {
        $root=dirname(__DIR__,4);
        $factory=(string)file_get_contents($root.'/app/Web/WebApplicationFactory.php');

        self::assertStringContainsString("'marketplace.delivery.manage'",$factory);
        self::assertStringContainsString("'/marketplace/manage/delivery/{listingId}'",$factory);
        self::assertStringContainsString('new MarketplaceDeliveryManageHandler(',$factory);
        self::assertStringContainsString("'marketplace.order.delivery'",$factory);
        self::assertStringContainsString("'/marketplace/orders/{orderId}/delivery/{itemId}/{action}'",$factory);
        self::assertStringContainsString('new MarketplaceOrderDeliveryHandler(',$factory);

        $manage=$this->routeBlock($factory,"'marketplace.delivery.manage'");
        $order=$this->routeBlock($factory,"'marketplace.order.delivery'");
        self::assertStringContainsString('[$marketplaceCsrf]',$manage);
        self::assertStringContainsString('[$marketplaceCsrf]',$order);
    }

    public function testDeliveryPagesExposeSupportHistoryAndNoSecretInGetOrderHtml():void
    {
        $root=dirname(__DIR__,4);
        $html=(string)file_get_contents($root.'/app/Web/Marketplace/MarketplacePurchaseHtml.php');
        $handler=(string)file_get_contents($root.'/app/Web/Marketplace/MarketplaceOrderDeliveryHandler.php');

        self::assertStringContainsString("'context_type'=>'marketplace_order'",$html);
        self::assertStringContainsString('Sipariş geçmişi',$html);
        self::assertStringContainsString("name=\"value\" maxlength=\"16384\"",$html);
        self::assertStringContainsString("if(\$action==='reveal')",$handler);
        self::assertStringContainsString("'Cache-Control','private, no-store'",$handler);
        self::assertStringContainsString("'Referrer-Policy','no-referrer'",$handler);
        self::assertStringContainsString("if(\$action==='download')",$handler);
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
