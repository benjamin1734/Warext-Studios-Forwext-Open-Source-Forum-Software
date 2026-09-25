<?php

declare(strict_types=1);

namespace Forwext\Core\Webhook;

final readonly class WebhookIssuedSubscription
{
    public function __construct(
        public WebhookSubscription $subscription,
        public string $secret,
    ) {
    }
}
