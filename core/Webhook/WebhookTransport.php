<?php

declare(strict_types=1);

namespace Forwext\Core\Webhook;

interface WebhookTransport
{
    /** @param array<string,string> $headers */
    public function post(
        ApprovedWebhookDestination $destination,
        string $body,
        array $headers,
        int $timeoutMilliseconds = 5000,
    ): WebhookTransportResult;
}
