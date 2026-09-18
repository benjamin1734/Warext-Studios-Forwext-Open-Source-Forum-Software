<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Oversight;

enum OversightAnomalySeverity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';
}
