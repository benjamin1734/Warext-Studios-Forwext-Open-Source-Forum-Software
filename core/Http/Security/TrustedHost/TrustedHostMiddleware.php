<?php

declare(strict_types=1);

namespace Forwext\Core\Http\Security\TrustedHost;

use Forwext\Core\Http\Canonical\CanonicalUrlMiddleware;
use Forwext\Core\Http\Middleware\MiddlewareInterface;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;

final readonly class TrustedHostMiddleware implements MiddlewareInterface
{
    public function __construct(private TrustedHostPolicy $policy)
    {
    }

    public function process(Request $request, RequestHandlerInterface $next): Response
    {
        $host = $request->attribute(CanonicalUrlMiddleware::ATTRIBUTE_HOST);
        if (!is_string($host) || !$this->policy->allows($host)) {
            return Response::text('Misdirected Request', 421)
                ->withHeader('Cache-Control', 'no-store');
        }

        return $next->handle($request);
    }
}
