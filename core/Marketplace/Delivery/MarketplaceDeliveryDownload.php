<?php

declare(strict_types=1);

namespace Forwext\Core\Marketplace\Delivery;

use InvalidArgumentException;

final readonly class MarketplaceDeliveryDownload
{
    public function __construct(
        public string $contents,
        public string $mediaType,
        public string $filename,
    ){
        if($this->contents===''||trim($this->filename)===''||strlen($this->filename)>191){
            throw new InvalidArgumentException('Marketplace delivery download is invalid.');
        }
    }
}
