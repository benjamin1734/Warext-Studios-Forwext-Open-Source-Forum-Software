<?php

declare(strict_types=1);

namespace Forwext\Core\Http\Security\Cors;

use Forwext\Core\Http\HttpMethod;

final readonly class CorsPolicy
{
    /** @var list<string> */
    private array $allowedOrigins;

    /** @var list<HttpMethod> */
    private array $allowedMethods;

    /** @var list<string> */
    private array $allowedHeaders;

    /**
     * @param list<string> $allowedOrigins
     * @param non-empty-list<HttpMethod> $allowedMethods
     * @param list<string> $allowedHeaders
     */
    public function __construct(
        array $allowedOrigins = [],
        array $allowedMethods = [HttpMethod::Get, HttpMethod::Head, HttpMethod::Post],
        array $allowedHeaders = ['Content-Type', 'X-CSRF-Token'],
        public bool $allowCredentials = false,
        public int $maxAge = 600,
    ) {
        if ($maxAge < 0 || $maxAge > 86400) {
            throw new CorsException('CORS max-age must be between 0 and 86400 seconds.');
        }
        if ($allowCredentials && in_array('*', $allowedOrigins, true)) {
            throw new CorsException('Credentialed CORS may not use wildcard origin.');
        }

        $origins = [];
        foreach ($allowedOrigins as $origin) {
            $origins[] = $origin === '*' ? '*' : $this->normalizeOrigin($origin);
        }
        $this->allowedOrigins = array_values(array_unique($origins));

        $methods = [];
        foreach ($allowedMethods as $method) {
            $methods[$method->value] = $method;
        }
        if ($methods === []) {
            throw new CorsException('CORS policy must allow at least one HTTP method.');
        }
        $this->allowedMethods = array_values($methods);

        $headers = [];
        foreach ($allowedHeaders as $header) {
            $normalized = strtolower(trim($header));
            if ($normalized === '' || preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $normalized) !== 1) {
                throw new CorsException('CORS policy contains an invalid allowed header name.');
            }
            $headers[$normalized] = $normalized;
        }
        $this->allowedHeaders = array_values($headers);
    }

    public function allowsOrigin(string $origin): bool
    {
        $normalized = $this->normalizeOrigin($origin);
        return in_array('*', $this->allowedOrigins, true) || in_array($normalized, $this->allowedOrigins, true);
    }

    public function responseOrigin(string $origin): string
    {
        return in_array('*', $this->allowedOrigins, true) ? '*' : $this->normalizeOrigin($origin);
    }

    public function allowsMethod(HttpMethod $method): bool
    {
        return in_array($method, $this->allowedMethods, true);
    }

    public function allowsHeader(string $header): bool
    {
        return in_array(strtolower(trim($header)), $this->allowedHeaders, true);
    }

    public function methodsHeader(): string
    {
        return implode(', ', array_map(static fn (HttpMethod $method): string => $method->value, $this->allowedMethods));
    }

    public function headersHeader(): string
    {
        return implode(', ', $this->allowedHeaders);
    }

    private function normalizeOrigin(string $origin): string
    {
        $origin = trim($origin);
        if ($origin === 'null') {
            return 'null';
        }

        $parts = parse_url($origin);
        if (
            $parts === false
            || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])
            || (($parts['path'] ?? '') !== '' && ($parts['path'] ?? '') !== '/')
        ) {
            throw new CorsException('CORS origin must be a bare http(s) origin.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new CorsException('CORS origin scheme must be http or https.');
        }

        $host = strtolower(rtrim((string) $parts['host'], '.'));
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        if (
            filter_var($host, FILTER_VALIDATE_IP) === false
            && preg_match('/^(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(?:\.(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?))*$/D', $host) !== 1
        ) {
            throw new CorsException('CORS origin host is invalid.');
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new CorsException('CORS origin port is invalid.');
        }
        $defaultPort = $scheme === 'https' ? 443 : 80;
        $hostForOrigin = str_contains($host, ':') ? '[' . $host . ']' : $host;

        return $scheme . '://' . $hostForOrigin . (($port !== null && $port !== $defaultPort) ? ':' . $port : '');
    }
}
