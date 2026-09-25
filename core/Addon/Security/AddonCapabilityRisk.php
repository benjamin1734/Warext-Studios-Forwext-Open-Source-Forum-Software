<?php

declare(strict_types=1);

namespace Forwext\Core\Addon\Security;

enum AddonCapabilityRisk: string
{
    case Informational = 'info';
    case Caution = 'caution';
    case High = 'high';
}
