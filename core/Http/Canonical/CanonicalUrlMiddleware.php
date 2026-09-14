<?php

declare(strict_types=1);

namespace Forwext\Core\Http\Canonical;

use Forwext\Core\Http\Middleware\MiddlewareInterface;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Proxy\TrustedProxyResolver;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;

final readonly class CanonicalUrlMiddleware implements MiddlewareInterface
{
    public const ATTRIBUTE_CLIENT_IP = 'client_ip';
    public const ATTRIBUTE_SCHEME = 'request_scheme';
    public const ATTRIBUTE_HOST = 'request_host';
    public const ATTRIBUTE_PORT = 'request_port';
    public const ATTRIBUTE_TRUSTED_PROXY = 'trusted_proxy';
    public const ATTRIBUTE_CLOUDFLARE_PROXY = 'cloudflare_proxy';

    public function __construct(
        private CanonicalUrl $canonicalUrl,
        private TrustedProxyResolver $proxyResolver,
    ) {
    }

    public function process(Request $request, RequestHandlerInterface $next): Response
    {
        $context = $this->proxyResolver->resolve($request);
        $request = $request
            ->withAttribute(self::ATTRIBUTE_CLIENT_IP, $context->clientIp)
            ->withAttribute(self::ATTRIBUTE_SCHEME, $context->scheme)
            ->withAttribute(self::ATTRIBUTE_HOST, $context->host)
            ->withAttribute(self::ATTRIBUTE_PORT, $context->port)
            ->withAttribute(self::ATTRIBUTE_TRUSTED_PROXY, $context->trustedProxy)
            ->withAttribute(self::ATTRIBUTE_CLOUDFLARE_PROXY, $context->cloudflareProxy);

        if (!$this->matchesCanonicalOrigin($context->scheme, $context->host, $context->port)) {
            if ($context->untrustedForwardingHeadersPresent) {
                return Response::text('Invalid proxy configuration.', 400)
                    ->withHeader('Cache-Control', 'no-store');
            }

            return Response::redirect($this->canonicalTarget($request), 308)
                ->withHeader('Cache-Control', 'no-store');
        }

        return $next->handle($request);
    }

    private function matchesCanonicalOrigin(string $scheme, string $host, int $port): bool
    {
        return $scheme === $this->canonicalUrl->scheme()
            && strtolower($host) === $this->canonicalUrl->host()
            && $port === $this->canonicalUrl->port();
    }

    private function canonicalTarget(Request $request): string
    {
        $uri = $request->uri();
        if (!str_starts_with($uri, '/')) {
            return $this->canonicalUrl->origin() . $this->canonicalUrl->basePath()->prepend('/');
        }

        return $this->canonicalUrl->origin() . $uri;
    }
}
