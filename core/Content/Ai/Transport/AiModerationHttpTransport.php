<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai\Transport;

interface AiModerationHttpTransport
{
    /**
     * @param array<string,string> $headers
     */
    public function postJson(
        AiModerationEndpoint $endpoint,
        array $headers,
        string $json,
        int $timeoutMilliseconds,
        int $maxResponseBytes = 524288,
    ): AiModerationHttpResponse;
}
