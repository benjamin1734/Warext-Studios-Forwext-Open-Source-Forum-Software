<?php

declare(strict_types=1);

namespace Forwext\Core\Http\Proxy;

final readonly class EffectiveRequestContext
{
    public function __construct(
        public string $scheme,
        public string $host,
        public int $port,
        public string $clientIp,
        public bool $trustedProxy,
        public bool $cloudflareProxy,
        public bool $untrustedForwardingHeadersPresent,
    ) {
    }

    public function authority(): string
    {
        $defaultPort = $this->scheme === 'https' ? 443 : 80;
        $host = str_contains($this->host, ':') ? '[' . $this->host . ']' : $this->host;

        return $this->port === $defaultPort ? $host : $host . ':' . $this->port;
    }
}
