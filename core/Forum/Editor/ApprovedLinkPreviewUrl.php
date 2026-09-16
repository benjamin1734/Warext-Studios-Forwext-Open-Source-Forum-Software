<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Editor;

final readonly class ApprovedLinkPreviewUrl
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
