<?php

declare(strict_types=1);

namespace Forwext\Core\Health;

use InvalidArgumentException;

final readonly class WritableDirectoryHealthCheck implements HealthCheck
{
    public function __construct(
        private string $checkName,
        private string $directory,
    ) {
        if (preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $checkName) !== 1) {
            throw new InvalidArgumentException('Writable-directory health check name is invalid.');
        }
        if ($directory === '' || str_contains($directory, "\0")) {
            throw new InvalidArgumentException('Writable-directory health check path is invalid.');
        }
    }

    public function name(): string
    {
        return $this->checkName;
    }

    public function check(): HealthCheckResult
    {
        if (is_link($this->directory)) {
            return new HealthCheckResult(
                $this->name(),
                HealthStatus::Unhealthy,
                'Required runtime directory may not be a symbolic link.',
            );
        }

        if (!is_dir($this->directory)) {
            return new HealthCheckResult(
                $this->name(),
                HealthStatus::Unhealthy,
                'Required runtime directory is missing.',
            );
        }

        if (!is_writable($this->directory)) {
            return new HealthCheckResult(
                $this->name(),
                HealthStatus::Unhealthy,
                'Required runtime directory is not writable.',
            );
        }

        return new HealthCheckResult(
            $this->name(),
            HealthStatus::Healthy,
            'Required runtime directory is writable.',
        );
    }
}
