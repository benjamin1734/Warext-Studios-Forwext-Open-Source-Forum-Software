<?php

declare(strict_types=1);

namespace Forwext\Core\Portfolio;

final readonly class PortfolioMediaDownload
{
    public function __construct(
        public string $contents,
        public string $mediaType,
        public string $filename,
    ) {
    }
}
