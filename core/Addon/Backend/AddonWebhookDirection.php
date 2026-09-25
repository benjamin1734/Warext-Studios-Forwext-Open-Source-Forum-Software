<?php

declare(strict_types=1);

namespace Forwext\Core\Addon\Backend;

enum AddonWebhookDirection: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';
}
