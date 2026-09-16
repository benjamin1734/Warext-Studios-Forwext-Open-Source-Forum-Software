<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Editor;

final readonly class LinkPreview
{
    public function __construct(
        public string $url,
        public string $host,
        public string $title,
        public string $description,
    ) {
    }
}
