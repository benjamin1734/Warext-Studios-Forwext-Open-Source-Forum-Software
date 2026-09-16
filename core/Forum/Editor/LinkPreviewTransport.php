<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Editor;

interface LinkPreviewTransport
{
    public function fetch(
        ApprovedLinkPreviewUrl $url,
        int $maxBytes = 262144,
        int $timeoutSeconds = 4,
    ): LinkPreviewHttpResponse;
}
