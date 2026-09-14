<?php

declare(strict_types=1);

namespace Forwext\Core\Http\Security\Headers;

use Forwext\Core\Http\Canonical\CanonicalUrlMiddleware;
use Forwext\Core\Http\Middleware\MiddlewareInterface;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;

final readonly class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function __construct(private SecurityHeaderPolicy $policy = new SecurityHeaderPolicy())
    {
    }

    public function process(Request $request, RequestHandlerInterface $next): Response
    {
        $response = $next->handle($request)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', $this->policy->referrerPolicy)
            ->withHeader('Permissions-Policy', $this->policy->permissionsPolicy)
            ->withHeader('Content-Security-Policy', $this->policy->contentSecurityPolicy)
            ->withHeader('X-Frame-Options', 'SAMEORIGIN')
            ->withHeader('X-Permitted-Cross-Domain-Policies', 'none');

        if (
            $this->policy->hstsMaxAge > 0
            && $request->attribute(CanonicalUrlMiddleware::ATTRIBUTE_SCHEME) === 'https'
        ) {
            $response = $response->withHeader(
                'Strict-Transport-Security',
                'max-age=' . $this->policy->hstsMaxAge,
            );
        }

        return $response;
    }
}
