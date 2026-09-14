<?php

declare(strict_types=1);

namespace Forwext\Core\Health;

enum HealthStatus: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Unhealthy = 'unhealthy';

    public function severity(): int
    {
        return match ($this) {
            self::Healthy => 0,
            self::Degraded => 1,
            self::Unhealthy => 2,
        };
    }
}
