<?php

declare(strict_types=1);

namespace Forwext\Core\Http\Security\Cors;

use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\MiddlewareInterface;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;

final readonly class CorsMiddleware implements MiddlewareInterface
{
    public function __construct(private CorsPolicy $policy)
    {
    }

    public function process(Request $request, RequestHandlerInterface $next): Response
    {
        $origin = $request->headers()->first('Origin');
        if ($origin === null) {
            return $next->handle($request);
        }

        try {
            if (!$this->policy->allowsOrigin($origin)) {
                return $this->rejected();
            }
        } catch (CorsException) {
            return $this->rejected();
        }

        $requestedMethod = $request->headers()->first('Access-Control-Request-Method');
        if ($request->method() === HttpMethod::Options && $requestedMethod !== null) {
            try {
                $method = HttpMethod::parse($requestedMethod);
            } catch (\Throwable) {
                return $this->rejected();
            }

            if (!$this->policy->allowsMethod($method) || !$this->preflightHeadersAllowed($request)) {
                return $this->rejected();
            }

            $response = new Response('', 204);
            return $this->applyHeaders($response, $origin, preflight: true);
        }

        if (!$this->policy->allowsMethod($request->method())) {
            return $this->rejected();
        }

        return $this->applyHeaders($next->handle($request), $origin, preflight: false);
    }

    private function preflightHeadersAllowed(Request $request): bool
    {
        $requested = $request->headers()->first('Access-Control-Request-Headers');
        if ($requested === null || trim($requested) === '') {
            return true;
        }

        foreach (explode(',', $requested) as $header) {
            if (!$this->policy->allowsHeader($header)) {
                return false;
            }
        }

        return true;
    }

    private function applyHeaders(Response $response, string $origin, bool $preflight): Response
    {
        $response = $response
            ->withHeader('Access-Control-Allow-Origin', $this->policy->responseOrigin($origin))
            ->withAddedHeader('Vary', 'Origin');

        if ($this->policy->allowCredentials) {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        if (!$preflight) {
            return $response;
        }

        $response = $response->withHeader('Access-Control-Allow-Methods', $this->policy->methodsHeader());
        if ($this->policy->headersHeader() !== '') {
            $response = $response->withHeader('Access-Control-Allow-Headers', $this->policy->headersHeader());
        }
        if ($this->policy->maxAge > 0) {
            $response = $response->withHeader('Access-Control-Max-Age', (string) $this->policy->maxAge);
        }

        return $response;
    }

    private function rejected(): Response
    {
        return Response::text('CORS request rejected.', 403)
            ->withHeader('Cache-Control', 'no-store');
    }
}
