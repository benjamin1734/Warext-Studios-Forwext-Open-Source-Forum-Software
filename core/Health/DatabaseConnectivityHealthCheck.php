<?php

declare(strict_types=1);

namespace Forwext\Core\Health;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use Throwable;

final readonly class DatabaseConnectivityHealthCheck implements HealthCheck
{
    public function __construct(private QueryExecutor $database)
    {
    }

    public function name(): string
    {
        return 'database';
    }

    public function check(): HealthCheckResult
    {
        try {
            $value = $this->database->fetchValue(new CompiledQuery('SELECT 1'));
        } catch (Throwable) {
            return new HealthCheckResult(
                $this->name(),
                HealthStatus::Unhealthy,
                'Database connectivity check failed.',
            );
        }

        return (int) $value === 1
            ? new HealthCheckResult($this->name(), HealthStatus::Healthy, 'Database connectivity is healthy.')
            : new HealthCheckResult($this->name(), HealthStatus::Unhealthy, 'Database returned an unexpected health result.');
    }
}
