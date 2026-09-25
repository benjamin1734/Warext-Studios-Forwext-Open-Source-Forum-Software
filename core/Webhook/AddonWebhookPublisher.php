<?php

declare(strict_types=1);

namespace Forwext\Core\Webhook;

use Forwext\Core\Addon\Backend\AddonBackendMetadataRegistry;
use Forwext\Core\Addon\Backend\AddonWebhookDirection;

final readonly class AddonWebhookPublisher
{
    public function __construct(
        private AddonBackendMetadataRegistry $metadata,
        private WebhookPlatformService $platform,
    ){}

    /** @param array<string,mixed> $data @return list<string> */
    public function publish(string $definitionKey,array $data):array
    {
        $definition=$this->metadata->webhook($definitionKey)
            ??throw new WebhookException('Add-on webhook definition does not exist.');
        if($definition->direction!==AddonWebhookDirection::Outbound){
            throw new WebhookException('Inbound add-on webhook definitions cannot be published as outbound events.');
        }
        return $this->platform->publish($definition->eventName,$data);
    }
}
