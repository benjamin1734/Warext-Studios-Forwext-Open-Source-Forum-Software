<?php

declare(strict_types=1);

namespace Forwext\Core\Webhook;

use Forwext\Core\Content\Ai\AiModerationProviderException;
use Forwext\Core\Content\Ai\AiModerationTimeoutException;
use Forwext\Core\Content\Ai\Transport\AiModerationEndpoint;
use Forwext\Core\Content\Ai\Transport\AiModerationHttpTransport;
use Forwext\Core\Content\Ai\Transport\PinnedHttpsAiModerationTransport;

final readonly class PinnedHttpsWebhookTransport implements WebhookTransport
{
    public function __construct(
        private AiModerationHttpTransport $http=new PinnedHttpsAiModerationTransport(),
    ){}

    public function post(
        ApprovedWebhookDestination $destination,
        string $body,
        array $headers,
        int $timeoutMilliseconds=5000,
    ):WebhookTransportResult{
        $endpoint=new AiModerationEndpoint(
            $destination->url,
            $destination->host,
            $destination->port,
            $destination->requestTarget,
            $destination->addresses,
        );

        try{
            $response=$this->http->postJson($endpoint,$headers,$body,$timeoutMilliseconds,65536);
        }catch(AiModerationTimeoutException){
            return WebhookTransportResult::failure('timeout',true);
        }catch(AiModerationProviderException){
            return WebhookTransportResult::failure('transport_error',true);
        }

        $status=$response->status;
        if($status>=200&&$status<=299)return WebhookTransportResult::success($status);
        if(in_array($status,[408,425,429],true)||$status>=500){
            return WebhookTransportResult::failure('http_'.$status,true,$status);
        }
        return WebhookTransportResult::failure('http_'.$status,false,$status);
    }
}
