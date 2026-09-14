<?php

declare(strict_types=1);

namespace Forwext\Core\Health;

use InvalidArgumentException;
use Throwable;

final readonly class HealthService
{
    /** @var non-empty-list<HealthCheck> */
    private array $checks;

    /** @param iterable<HealthCheck> $checks */
    public function __construct(iterable $checks)
    {
        /** @var list<HealthCheck> $items */
        $items = [];
        $names = [];

        foreach ($checks as $check) {
            $name = $check->name();
            if (preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $name) !== 1) {
                throw new InvalidArgumentException('Health check name is invalid.');
            }
            if (isset($names[$name])) {
                throw new InvalidArgumentException(sprintf('Health check "%s" is registered more than once.', $name));
            }

            $names[$name] = true;
            $items[] = $check;
        }

        if ($items === []) {
            throw new InvalidArgumentException('At least one health check is required.');
        }

        /** @var non-empty-list<HealthCheck> $items */
        $this->checks = $items;
    }

    public function report(): HealthReport
    {
        $status = HealthStatus::Healthy;
        $results = [];

        foreach ($this->checks as $check) {
            try {
                $result = $check->check();
                if ($result->name !== $check->name()) {
                    $result = new HealthCheckResult(
                        $check->name(),
                        HealthStatus::Unhealthy,
                        'Health check returned a mismatched result name.',
                    );
                }
            } catch (Throwable) {
                $result = new HealthCheckResult(
                    $check->name(),
                    HealthStatus::Unhealthy,
                    'Health check raised an exception.',
                );
            }

            $results[] = $result;
            if ($result->status->severity() > $status->severity()) {
                $status = $result->status;
            }
        }

        /** @var non-empty-list<HealthCheckResult> $results */
        return new HealthReport($status, $results);
    }
}
