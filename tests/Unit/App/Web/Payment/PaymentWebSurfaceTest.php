<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web\Payment;

use PHPUnit\Framework\TestCase;

final class PaymentWebSurfaceTest extends TestCase
{
    public function testPaymentHumanMutationsUseCsrfWhileWebhookDoesNot():void
    {
        $root=dirname(__DIR__,5);
        $factory=(string)file_get_contents($root.'/app/Web/WebApplicationFactory.php');

        $paymentRoute=$this->routeBlock($factory,"'marketplace.order.payment'");
        self::assertStringContainsString('[$marketplaceCsrf]',$paymentRoute);

        $adminRoute=$this->routeBlock($factory,"'payment.manage'");
        self::assertStringContainsString('[$paymentCsrf]',$adminRoute);

        $webhookRoute=$this->routeBlock($factory,"'payment.webhook'");
        self::assertStringContainsString('new PaymentWebhookHandler($payments)',$webhookRoute);
        self::assertStringNotContainsString('$paymentCsrf',$webhookRoute);
        self::assertStringNotContainsString('$marketplaceCsrf',$webhookRoute);
    }

    public function testProviderRegistryIsOptionalAndNativePaymentFormsCarryIdempotencyKeys():void
    {
        $root=dirname(__DIR__,5);
        $factory=(string)file_get_contents($root.'/app/Web/WebApplicationFactory.php');
        $orderHtml=(string)file_get_contents($root.'/app/Web/Marketplace/MarketplacePurchaseHtml.php');

        self::assertStringContainsString('private ?PaymentProviderRegistry $paymentProviders=null',$factory);
        self::assertStringContainsString('$this->paymentProviders??new PaymentProviderRegistry()',$factory);
        self::assertStringContainsString('name="idempotency_key"',$orderHtml);
        self::assertStringContainsString('name="payment_cancel_key"',$orderHtml);
    }

    public function testWebhookHandlerUsesRawBodyAndProviderVerificationBoundary():void
    {
        $root=dirname(__DIR__,5);
        $handler=(string)file_get_contents($root.'/app/Web/Payment/PaymentWebhookHandler.php');
        $service=(string)file_get_contents($root.'/core/Payment/PaymentService.php');

        self::assertStringContainsString('$request->rawBody()',$handler);
        self::assertStringContainsString('$request->headers()->all()',$handler);
        self::assertStringContainsString('$provider->verifyWebhook($request)',$service);
        self::assertStringContainsString("hash('sha256',$request->rawBody)",$service);
        self::assertStringNotContainsString('rawBody', (string)file_get_contents($root.'/app/Web/Payment/PaymentHtml.php'));
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
