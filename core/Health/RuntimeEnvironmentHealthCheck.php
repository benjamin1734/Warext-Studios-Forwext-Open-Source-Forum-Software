<?php

declare(strict_types=1);

namespace Forwext\Core\Health;

final readonly class RuntimeEnvironmentHealthCheck implements HealthCheck
{
    /** @param list<string> $requiredExtensions */
    public function __construct(
        private int $minimumPhpVersionId = 80400,
        private array $requiredExtensions = ['json', 'openssl', 'pdo'],
    ) {
    }

    public function name(): string
    {
        return 'runtime';
    }

    public function check(): HealthCheckResult
    {
        $missing = [];
        foreach ($this->requiredExtensions as $extension) {
            if (!extension_loaded($extension)) {
                $missing[] = $extension;
            }
        }

        if (PHP_VERSION_ID < $this->minimumPhpVersionId || $missing !== []) {
            return new HealthCheckResult(
                $this->name(),
                HealthStatus::Unhealthy,
                'Runtime requirements are not satisfied.',
                [
                    'php_version' => PHP_VERSION,
                    'missing_extensions' => implode(',', $missing),
                ],
            );
        }

        return new HealthCheckResult(
            $this->name(),
            HealthStatus::Healthy,
            'Runtime requirements are satisfied.',
            ['php_version' => PHP_VERSION],
        );
    }
}
