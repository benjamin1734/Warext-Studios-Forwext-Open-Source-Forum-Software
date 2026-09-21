<?php

declare(strict_types=1);

namespace Forwext\Core\Payment;

final readonly class PaymentProviderCapabilities
{
    public function __construct(
        public bool $refunds=true,
        public bool $cancellation=true,
    ){}
}
