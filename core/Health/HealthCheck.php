<?php

declare(strict_types=1);

namespace Forwext\Core\Health;

interface HealthCheck
{
    public function name(): string;

    public function check(): HealthCheckResult;
}
