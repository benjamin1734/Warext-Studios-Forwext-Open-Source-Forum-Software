<?php

declare(strict_types=1);

namespace Forwext\Core\Subscription;

enum SubscriptionState:string
{
    case Active='active';
    case Expired='expired';
    case Revoked='revoked';
}
