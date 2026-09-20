<?php

declare(strict_types=1);

namespace Forwext\Core\Marketplace;

final readonly class MarketplaceMediaDownload
{
    public function __construct(
        public string $contents,
        public string $mediaType,
        public string $filename,
    ){}
}
