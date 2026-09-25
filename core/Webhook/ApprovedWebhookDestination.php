<?php

declare(strict_types=1);

namespace Forwext\Core\Webhook;

final readonly class ApprovedWebhookDestination
{
    /** @param list<string> $addresses */
    public function __construct(
        public string $url,
        public string $host,
        public int $port,
        public string $requestTarget,
        public array $addresses,
    ) {
    }
}
