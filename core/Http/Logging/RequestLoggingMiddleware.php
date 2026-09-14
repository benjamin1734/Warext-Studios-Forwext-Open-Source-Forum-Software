<?php

declare(strict_types=1);

namespace Forwext\Core\Http\Logging;

use Forwext\Core\Http\Canonical\CanonicalUrlMiddleware;
use Forwext\Core\Http\Middleware\MiddlewareInterface;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Middleware\RequestIdMiddleware;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Logging\LogLevel;
use Forwext\Core\Logging\StructuredLogger;
use Forwext\Core\Routing\Router;

final readonly class RequestLoggingMiddleware implements MiddlewareInterface
{
    public function __construct(private StructuredLogger $logger)
    {
    }

    public function process(Request $request, RequestHandlerInterface $next): Response
    {
        $started = hrtime(true);
        $response = $next->handle($request);
        $durationMilliseconds = (hrtime(true) - $started) / 1_000_000;
        $path = explode('?', $request->uri(), 2)[0];

        $context = [
            'request_id' => $request->attribute(RequestIdMiddleware::ATTRIBUTE),
            'method' => $request->method()->value,
            'path' => $path,
            'route' => $request->attribute(Router::ATTRIBUTE_ROUTE_NAME),
            'client_ip' => $request->attribute(CanonicalUrlMiddleware::ATTRIBUTE_CLIENT_IP),
            'status' => $response->status(),
            'duration_ms' => round($durationMilliseconds, 3),
        ];

        $this->logger->log(LogLevel::Info, 'HTTP request completed', $context);

        return $response;
    }
}
