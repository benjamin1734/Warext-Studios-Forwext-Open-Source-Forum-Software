<?php

declare(strict_types=1);

namespace Forwext\App\Web\Api\V1;

use Forwext\Core\Api\V1\Security\ApiV1Principal;
use Forwext\Core\Http\Canonical\CanonicalUrlMiddleware;
use Forwext\Core\Http\Middleware\MiddlewareInterface;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\RateLimit\RateLimitPolicy;
use Forwext\Core\Http\Security\RateLimit\RateLimitStore;

final readonly class ApiV1RateLimitMiddleware implements MiddlewareInterface
{
    private RateLimitPolicy $anonymousPolicy;
    private RateLimitPolicy $credentialPolicy;

    public function __construct(
        private RateLimitStore $store,
        ?RateLimitPolicy $anonymousPolicy = null,
        ?RateLimitPolicy $credentialPolicy = null,
    ) {
        $this->anonymousPolicy = $anonymousPolicy ?? new RateLimitPolicy('api.v1.anonymous', 120, 60);
        $this->credentialPolicy = $credentialPolicy ?? new RateLimitPolicy('api.v1.credential', 600, 60);
    }

    public function process(Request $request, RequestHandlerInterface $next): Response
    {
        $principal = $request->attribute(ApiV1SecurityMiddleware::ATTRIBUTE_PRINCIPAL);
        if ($principal instanceof ApiV1Principal) {
            $identity = 'credential:' . $principal->credentialId->value();
            $policy = $this->credentialPolicy;
        } else {
            $identity = 'ip:' . $this->clientIp($request);
            $policy = $this->anonymousPolicy;
        }

        $now = time();
        $result = $this->store->consume($identity, $policy, $now);
        if (!$result->allowed) {
            return ApiV1ErrorResponder::error(
                'rate_limited',
                'API request rate limit exceeded.',
                429,
                ['retry_after'=>$result->retryAfter($now)],
            )
                ->withHeader('Retry-After', (string) $result->retryAfter($now))
                ->withHeader('X-RateLimit-Limit', (string) $result->limit)
                ->withHeader('X-RateLimit-Remaining', '0')
                ->withHeader('X-RateLimit-Reset', (string) $result->resetAt);
        }

        return $next->handle($request)
            ->withHeader('X-RateLimit-Limit', (string) $result->limit)
            ->withHeader('X-RateLimit-Remaining', (string) $result->remaining)
            ->withHeader('X-RateLimit-Reset', (string) $result->resetAt);
    }

    private function clientIp(Request $request): string
    {
        $canonical = $request->attribute(CanonicalUrlMiddleware::ATTRIBUTE_CLIENT_IP);
        if (is_string($canonical) && $canonical !== '') {
            return $canonical;
        }
        $remote = $request->server()['REMOTE_ADDR'] ?? null;

        return is_string($remote) && $remote !== '' ? $remote : 'unknown';
    }
}
