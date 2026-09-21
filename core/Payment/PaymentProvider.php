<?php

declare(strict_types=1);

namespace Forwext\Core\Payment;

interface PaymentProvider
{
    public function key():string;
    public function capabilities():PaymentProviderCapabilities;
    public function create(PaymentCreateRequest $request):PaymentProviderResult;
    public function verifyWebhook(PaymentWebhookRequest $request):PaymentWebhookEvent;
    public function cancel(PaymentCancelRequest $request):PaymentProviderResult;
    public function refund(PaymentRefundRequest $request):PaymentRefundResult;
}
