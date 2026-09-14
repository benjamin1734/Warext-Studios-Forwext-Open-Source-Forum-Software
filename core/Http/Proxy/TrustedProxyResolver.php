<?php

declare(strict_types=1);

namespace Forwext\Core\Http\Proxy;

use Forwext\Core\Http\HttpException;
use Forwext\Core\Http\Request;
use JsonException;

final readonly class TrustedProxyResolver
{
    public function __construct(
        private CidrSet $trustedProxies = new CidrSet(),
        private CidrSet $cloudflareProxies = new CidrSet(),
    ) {
    }

    public function resolve(Request $request): EffectiveRequestContext
    {
        $remoteAddress = $request->server()['REMOTE_ADDR'] ?? null;
        if (!is_string($remoteAddress) || filter_var($remoteAddress, FILTER_VALIDATE_IP) === false) {
            throw new HttpException('REMOTE_ADDR must contain a valid IP address.');
        }

        $isCloudflare = $this->cloudflareProxies->contains($remoteAddress);
        $isTrusted = $isCloudflare || $this->trustedProxies->contains($remoteAddress);
        $forwardingPresent = $this->hasForwardingHeaders($request);

        $scheme = $this->directScheme($request);
        [$host, $hostPort] = $this->parseAuthority($this->directAuthority($request));
        $port = $hostPort ?? $this->directPort($request, $scheme);
        $clientIp = $remoteAddress;

        if ($isTrusted) {
            $scheme = $this->forwardedScheme($request, $isCloudflare) ?? $scheme;
            [$forwardedHost, $forwardedHostPort] = $this->forwardedAuthority($request) ?? [$host, $hostPort];
            $host = $forwardedHost;
            $port = $this->forwardedPort($request)
                ?? $forwardedHostPort
                ?? ($scheme === 'https' ? 443 : 80);
            $clientIp = $isCloudflare
                ? ($this->cloudflareClientIp($request) ?? $this->forwardedClientIp($request, $remoteAddress))
                : $this->forwardedClientIp($request, $remoteAddress);
        }

        return new EffectiveRequestContext(
            $scheme,
            $host,
            $port,
            $clientIp,
            $isTrusted,
            $isCloudflare,
            !$isTrusted && $forwardingPresent,
        );
    }

    private function directScheme(Request $request): string
    {
        $https = $request->server()['HTTPS'] ?? null;
        if (is_string($https) && in_array(strtolower($https), ['on', '1', 'true'], true)) {
            return 'https';
        }

        $port = $request->server()['SERVER_PORT'] ?? null;
        return ((string) $port === '443') ? 'https' : 'http';
    }

    private function directAuthority(Request $request): string
    {
        $host = $request->headers()->first('Host');
        if ($host !== null) {
            return $host;
        }

        $serverName = $request->server()['SERVER_NAME'] ?? null;
        if (!is_string($serverName) || $serverName === '') {
            throw new HttpException('Request host is unavailable.');
        }

        return $serverName;
    }

    private function directPort(Request $request, string $scheme): int
    {
        $value = $request->server()['SERVER_PORT'] ?? null;
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $port = (int) $value;
            if ($port >= 1 && $port <= 65535) {
                return $port;
            }
        }

        return $scheme === 'https' ? 443 : 80;
    }

    private function forwardedScheme(Request $request, bool $cloudflare): ?string
    {
        $value = $this->firstCsv($request->headers()->first('X-Forwarded-Proto'))
            ?? $this->forwardedParameter($request, 'proto');

        if ($value !== null) {
            $value = strtolower($value);
            if ($value !== 'http' && $value !== 'https') {
                throw new HttpException('Trusted proxy sent an invalid forwarded scheme.');
            }
            return $value;
        }

        if (!$cloudflare) {
            return null;
        }

        $visitor = $request->headers()->first('CF-Visitor');
        if ($visitor === null) {
            return null;
        }

        try {
            $decoded = json_decode($visitor, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new HttpException('Cloudflare sent an invalid CF-Visitor header.', previous: $exception);
        }

        $scheme = is_array($decoded) ? ($decoded['scheme'] ?? null) : null;
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new HttpException('Cloudflare CF-Visitor scheme is invalid.');
        }

        return $scheme;
    }

    /** @return array{string, int|null}|null */
    private function forwardedAuthority(Request $request): ?array
    {
        $value = $this->firstCsv($request->headers()->first('X-Forwarded-Host'))
            ?? $this->forwardedParameter($request, 'host');

        return $value === null ? null : $this->parseAuthority($value);
    }

    private function forwardedPort(Request $request): ?int
    {
        $value = $this->firstCsv($request->headers()->first('X-Forwarded-Port'));
        if ($value === null) {
            return null;
        }

        if (!ctype_digit($value)) {
            throw new HttpException('Trusted proxy sent an invalid X-Forwarded-Port value.');
        }

        $port = (int) $value;
        if ($port < 1 || $port > 65535) {
            throw new HttpException('Trusted proxy sent an out-of-range forwarded port.');
        }

        return $port;
    }

    private function cloudflareClientIp(Request $request): ?string
    {
        $value = $request->headers()->first('CF-Connecting-IP');
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if (filter_var($value, FILTER_VALIDATE_IP) === false) {
            throw new HttpException('Cloudflare sent an invalid CF-Connecting-IP value.');
        }

        return $value;
    }

    private function forwardedClientIp(Request $request, string $remoteAddress): string
    {
        $chain = $this->xForwardedForChain($request) ?? $this->standardForwardedForChain($request);
        if ($chain === null || $chain === []) {
            return $remoteAddress;
        }

        $chain[] = $remoteAddress;
        for ($index = count($chain) - 1; $index >= 0; --$index) {
            $address = $chain[$index];
            if (!$this->trustedProxies->contains($address) && !$this->cloudflareProxies->contains($address)) {
                return $address;
            }
        }

        return $chain[0];
    }

    /** @return list<string>|null */
    private function xForwardedForChain(Request $request): ?array
    {
        $header = $request->headers()->first('X-Forwarded-For');
        if ($header === null) {
            return null;
        }

        $chain = array_map('trim', explode(',', $header));
        foreach ($chain as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP) === false) {
                throw new HttpException('Trusted proxy sent an invalid X-Forwarded-For chain.');
            }
        }

        return $chain;
    }

    /** @return list<string>|null */
    private function standardForwardedForChain(Request $request): ?array
    {
        $header = $request->headers()->first('Forwarded');
        if ($header === null) {
            return null;
        }

        $chain = [];
        foreach (explode(',', $header) as $element) {
            $value = $this->forwardedParameterFromElement($element, 'for');
            if ($value === null) {
                throw new HttpException('Trusted proxy Forwarded element is missing a for parameter.');
            }
            $chain[] = $this->normalizeForwardedFor($value);
        }

        return $chain;
    }

    private function normalizeForwardedFor(string $value): string
    {
        $value = trim($value);
        if ($value === '' || strtolower($value) === 'unknown' || str_starts_with($value, '_')) {
            throw new HttpException('Trusted proxy sent an unsupported Forwarded for value.');
        }

        if (str_starts_with($value, '[')) {
            $end = strpos($value, ']');
            if ($end === false) {
                throw new HttpException('Trusted proxy sent an invalid bracketed Forwarded for value.');
            }
            $ip = substr($value, 1, $end - 1);
            $rest = substr($value, $end + 1);
            if ($rest !== '' && (!str_starts_with($rest, ':') || !ctype_digit(substr($rest, 1)))) {
                throw new HttpException('Trusted proxy sent an invalid Forwarded for port.');
            }
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new HttpException('Trusted proxy sent an invalid Forwarded IPv6 address.');
            }
            return $ip;
        }

        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return $value;
        }

        if (substr_count($value, ':') === 1) {
            [$ip, $port] = explode(':', $value, 2);
            if (ctype_digit($port) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $ip;
            }
        }

        throw new HttpException('Trusted proxy sent an invalid Forwarded for value.');
    }

    /** @return array{string, int|null} */
    private function parseAuthority(string $authority): array
    {
        $authority = trim($authority);
        if ($authority === '' || preg_match('/[\x00-\x20\x7F]/', $authority) === 1) {
            throw new HttpException('Request authority is invalid.');
        }

        if (str_starts_with($authority, '[')) {
            $end = strpos($authority, ']');
            if ($end === false) {
                throw new HttpException('IPv6 request authority is invalid.');
            }
            $host = substr($authority, 1, $end - 1);
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new HttpException('IPv6 request host is invalid.');
            }
            $rest = substr($authority, $end + 1);
            if ($rest === '') {
                return [strtolower($host), null];
            }
            if (!str_starts_with($rest, ':') || !ctype_digit(substr($rest, 1))) {
                throw new HttpException('IPv6 request authority port is invalid.');
            }
            $port = (int) substr($rest, 1);
            if ($port < 1 || $port > 65535) {
                throw new HttpException('Request authority port is out of range.');
            }
            return [strtolower($host), $port];
        }

        if (substr_count($authority, ':') > 1) {
            throw new HttpException('IPv6 hosts in request authority must use brackets.');
        }

        $host = $authority;
        $port = null;
        if (str_contains($authority, ':')) {
            [$host, $portText] = explode(':', $authority, 2);
            if ($portText === '' || !ctype_digit($portText)) {
                throw new HttpException('Request authority port is invalid.');
            }
            $port = (int) $portText;
            if ($port < 1 || $port > 65535) {
                throw new HttpException('Request authority port is out of range.');
            }
        }

        $host = strtolower(rtrim($host, '.'));
        if (
            filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            && preg_match('/^(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(?:\.(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?))*$/D', $host) !== 1
        ) {
            throw new HttpException('Request host is invalid.');
        }

        return [$host, $port];
    }

    private function forwardedParameter(Request $request, string $name): ?string
    {
        $header = $request->headers()->first('Forwarded');
        if ($header === null) {
            return null;
        }

        return $this->forwardedParameterFromElement(explode(',', $header, 2)[0], $name);
    }

    private function forwardedParameterFromElement(string $element, string $name): ?string
    {
        foreach (explode(';', $element) as $part) {
            if (!str_contains($part, '=')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode('=', $part, 2));
            if (strtolower($key) !== $name) {
                continue;
            }

            if (strlen($value) >= 2 && ord($value[0]) === 34 && ord($value[strlen($value) - 1]) === 34) {
                $value = substr($value, 1, -1);
                $value = str_replace(
                    [chr(92) . chr(34), chr(92) . chr(92)],
                    [chr(34), chr(92)],
                    $value,
                );
            }

            if ($value === '' || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw new HttpException('Trusted proxy sent an invalid Forwarded parameter.');
            }

            return $value;
        }

        return null;
    }

    private function firstCsv(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $first = trim(explode(',', $value, 2)[0]);
        return $first === '' ? null : $first;
    }

    private function hasForwardingHeaders(Request $request): bool
    {
        foreach ([
            'Forwarded',
            'X-Forwarded-For',
            'X-Forwarded-Proto',
            'X-Forwarded-Host',
            'X-Forwarded-Port',
            'CF-Connecting-IP',
            'CF-Visitor',
        ] as $header) {
            if ($request->headers()->has($header)) {
                return true;
            }
        }

        return false;
    }
}
