<?php

declare(strict_types=1);

namespace Forwext\Core\Advertising;

enum AdvertisingEventType:string
{
    case Impression='impression';
    case Click='click';
}
