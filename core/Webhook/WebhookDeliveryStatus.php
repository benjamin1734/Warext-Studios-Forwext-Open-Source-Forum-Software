<?php

declare(strict_types=1);

namespace Forwext\Core\Webhook;

enum WebhookDeliveryStatus: string
{
    case Pending = 'pending';
    case RetryScheduled = 'retry_scheduled';
    case Delivered = 'delivered';
    case Failed = 'failed';
}
