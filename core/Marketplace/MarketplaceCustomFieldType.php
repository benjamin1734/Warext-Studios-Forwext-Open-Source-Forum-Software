<?php

declare(strict_types=1);

namespace Forwext\Core\Marketplace;

enum MarketplaceCustomFieldType:string
{
    case Text='text';
    case Integer='integer';
    case Boolean='boolean';
    case Select='select';
}
