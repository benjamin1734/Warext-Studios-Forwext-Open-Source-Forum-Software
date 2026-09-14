<?php

declare(strict_types=1);

namespace Forwext\Core\Http\Middleware;

use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;

final readonly class RequestIdMiddleware implements MiddlewareInterface
{
    public const ATTRIBUTE = 'request_id';
    public const HEADER = 'X-Request-ID';

    public function process(Request $request, RequestHandlerInterface $next): Response
    {
        $requestId = $this->incomingRequestId($request) ?? bin2hex(random_bytes(16));
        $response = $next->handle($request->withAttribute(self::ATTRIBUTE, $requestId));

        return $response->withHeader(self::HEADER, $requestId);
    }

    private function incomingRequestId(Request $request): ?string
    {
        $candidate = $request->headers()->first(self::HEADER);
        if ($candidate === null) {
            return null;
        }

        $candidate = trim($candidate);

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{7,127}$/D', $candidate) !== 1) {
            return null;
        }

        return $candidate;
    }
}
