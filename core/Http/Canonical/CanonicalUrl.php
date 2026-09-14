<?php

declare(strict_types=1);

namespace Forwext\Core\Http\Canonical;

use Forwext\Core\Http\HttpException;
use Forwext\Core\Routing\BasePath;

final readonly class CanonicalUrl
{
    private string $scheme;
    private string $host;
    private int $port;
    private BasePath $basePath;

    public function __construct(string $url)
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new HttpException('Canonical URL must include scheme and host.');
        }

        if (isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])) {
            throw new HttpException('Canonical URL may not include credentials, query or fragment.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new HttpException('Canonical URL scheme must be http or https.');
        }

        $host = strtolower(rtrim((string) $parts['host'], '.'));
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        if (
            filter_var($host, FILTER_VALIDATE_IP) === false
            && preg_match('/^(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(?:\.(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?))*$/D', $host) !== 1
        ) {
            throw new HttpException('Canonical URL host is invalid.');
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        if ($port < 1 || $port > 65535) {
            throw new HttpException('Canonical URL port is invalid.');
        }

        $this->scheme = $scheme;
        $this->host = $host;
        $this->port = $port;
        $this->basePath = new BasePath((string) ($parts['path'] ?? ''));
    }

    public function scheme(): string
    {
        return $this->scheme;
    }

    public function host(): string
    {
        return $this->host;
    }

    public function port(): int
    {
        return $this->port;
    }

    public function basePath(): BasePath
    {
        return $this->basePath;
    }

    public function origin(): string
    {
        $host = str_contains($this->host, ':') ? '[' . $this->host . ']' : $this->host;
        $defaultPort = $this->scheme === 'https' ? 443 : 80;

        return sprintf(
            '%s://%s%s',
            $this->scheme,
            $host,
            $this->port === $defaultPort ? '' : ':' . $this->port,
        );
    }
}
