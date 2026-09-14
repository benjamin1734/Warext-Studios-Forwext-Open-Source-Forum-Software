<?php

declare(strict_types=1);

namespace Forwext\Core\Http\Health;

use Forwext\Core\Health\HealthCheckResult;
use Forwext\Core\Health\HealthService;
use Forwext\Core\Health\HealthStatus;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;

final readonly class HealthHandler implements RequestHandlerInterface
{
    public function __construct(
        private HealthService $health,
        private bool $detailed = false,
    ) {
    }

    public function handle(Request $request): Response
    {
        $report = $this->health->report();
        $statusCode = $report->status === HealthStatus::Unhealthy ? 503 : 200;
        $payload = ['status' => $report->status->value];

        if ($this->detailed) {
            $payload['checks'] = array_map(
                static fn (HealthCheckResult $check): array => [
                    'name' => $check->name,
                    'status' => $check->status->value,
                    'message' => $check->message,
                    'details' => $check->details,
                ],
                $report->checks,
            );
        }

        return Response::json($payload, $statusCode)
            ->withHeader('Cache-Control', 'no-store');
    }
}
