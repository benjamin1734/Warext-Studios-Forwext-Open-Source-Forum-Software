<?php

declare(strict_types=1);

namespace Forwext\Core\Http\Security\RateLimit;

use Closure;
use Forwext\Core\Http\Canonical\CanonicalUrlMiddleware;
use Forwext\Core\Http\Middleware\MiddlewareInterface;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;

final readonly class RateLimitMiddleware implements MiddlewareInterface
{
    /** @var Closure(Request): string */
    private Closure $keyResolver;

    /** @param Closure(Request): string|null $keyResolver */
    public function __construct(
        private RateLimitStore $store,
        private RateLimitPolicy $policy,
        ?Closure $keyResolver = null,
    ) {
        $this->keyResolver = $keyResolver ?? static function (Request $request): string {
            $clientIp = $request->attribute(CanonicalUrlMiddleware::ATTRIBUTE_CLIENT_IP);
            if (is_string($clientIp) && $clientIp !== '') {
                return $clientIp;
            }

            $remote = $request->server()['REMOTE_ADDR'] ?? null;
            return is_string($remote) && $remote !== '' ? $remote : 'unknown';
        };
    }

    public function process(Request $request, RequestHandlerInterface $next): Response
    {
        $now = time();
        $identity = ($this->keyResolver)($request);
        if ($identity === '') {
            throw new RateLimitException('Rate-limit key resolver returned an empty identity.');
        }

        $result = $this->store->consume($identity, $this->policy, $now);
        if (!$result->allowed) {
            return Response::text('Too Many Requests', 429)
                ->withHeader('Retry-After', (string) $result->retryAfter($now))
                ->withHeader('X-RateLimit-Limit', (string) $result->limit)
                ->withHeader('X-RateLimit-Remaining', '0')
                ->withHeader('X-RateLimit-Reset', (string) $result->resetAt)
                ->withHeader('Cache-Control', 'no-store');
        }

        return $next->handle($request)
            ->withHeader('X-RateLimit-Limit', (string) $result->limit)
            ->withHeader('X-RateLimit-Remaining', (string) $result->remaining)
            ->withHeader('X-RateLimit-Reset', (string) $result->resetAt);
    }
}
