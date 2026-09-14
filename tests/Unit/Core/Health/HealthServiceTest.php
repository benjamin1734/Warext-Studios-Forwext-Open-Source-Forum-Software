<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Health;

use Forwext\Core\Health\HealthCheck;
use Forwext\Core\Health\HealthCheckResult;
use Forwext\Core\Health\HealthService;
use Forwext\Core\Health\HealthStatus;
use Forwext\Core\Http\Health\HealthHandler;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Request;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HealthServiceTest extends TestCase
{
    public function testWorstHealthStatusWins(): void
    {
        $service = new HealthService([
            new FixedHealthCheck('runtime', HealthStatus::Healthy),
            new FixedHealthCheck('storage', HealthStatus::Degraded),
            new FixedHealthCheck('database', HealthStatus::Unhealthy),
        ]);

        $report = $service->report();

        self::assertSame(HealthStatus::Unhealthy, $report->status);
        self::assertCount(3, $report->checks);
    }

    public function testThrownHealthCheckBecomesUnhealthyWithoutLeakingException(): void
    {
        $service = new HealthService([new ThrowingHealthCheck()]);
        $report = $service->report();

        self::assertSame(HealthStatus::Unhealthy, $report->status);
        self::assertSame('Health check raised an exception.', $report->checks[0]->message);
        self::assertStringNotContainsString('secret failure', $report->checks[0]->message);
    }

    public function testPublicHealthResponseIsMinimalAndUnhealthyReturns503(): void
    {
        $handler = new HealthHandler(new HealthService([
            new FixedHealthCheck('database', HealthStatus::Unhealthy, 'connection unavailable'),
        ]));

        $response = $handler->handle(new Request(HttpMethod::Get, '/health'));

        self::assertSame(503, $response->status());
        self::assertSame('{"status":"unhealthy"}', $response->body());
        self::assertStringNotContainsString('connection unavailable', $response->body());
    }

    public function testDetailedHealthResponseIncludesSafeCheckDetails(): void
    {
        $handler = new HealthHandler(
            new HealthService([
                new FixedHealthCheck('runtime', HealthStatus::Healthy, 'ok', ['php' => '8.4']),
            ]),
            detailed: true,
        );

        $response = $handler->handle(new Request(HttpMethod::Get, '/health/internal'));

        self::assertSame(200, $response->status());
        self::assertStringContainsString('"checks"', $response->body());
        self::assertStringContainsString('"runtime"', $response->body());
    }
}

final readonly class FixedHealthCheck implements HealthCheck
{
    /** @param array<string, scalar|null> $details */
    public function __construct(
        private string $checkName,
        private HealthStatus $status,
        private string $message = '',
        private array $details = [],
    ) {
    }

    public function name(): string
    {
        return $this->checkName;
    }

    public function check(): HealthCheckResult
    {
        return new HealthCheckResult($this->checkName, $this->status, $this->message, $this->details);
    }
}

final class ThrowingHealthCheck implements HealthCheck
{
    public function name(): string
    {
        return 'throwing';
    }

    public function check(): HealthCheckResult
    {
        throw new RuntimeException('secret failure');
    }
}
