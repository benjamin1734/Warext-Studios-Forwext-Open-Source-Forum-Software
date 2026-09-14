<?php

declare(strict_types=1);

namespace Forwext\Core\Http\Cookie;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Forwext\Core\Http\HttpException;

final readonly class ResponseCookie
{
    public function __construct(
        public string $name,
        public string $value,
        public ?DateTimeImmutable $expires = null,
        public ?int $maxAge = null,
        public string $path = '/',
        public ?string $domain = null,
        public bool $secure = true,
        public bool $httpOnly = true,
        public SameSite $sameSite = SameSite::Lax,
    ) {
        if ($name === '' || preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $name) !== 1) {
            throw new HttpException('Invalid cookie name.');
        }

        $this->assertCookieValue($value);
        $this->assertPath($path);
        $this->assertDomain($domain);

        if ($maxAge !== null && $maxAge < 0) {
            throw new HttpException('Cookie Max-Age may not be negative.');
        }

        if ($sameSite === SameSite::None && !$secure) {
            throw new HttpException('SameSite=None cookies must be Secure.');
        }

        if (str_starts_with($name, '__Secure-') && !$secure) {
            throw new HttpException('__Secure- cookies must be Secure.');
        }

        if (str_starts_with($name, '__Host-') && (!$secure || $domain !== null || $path !== '/')) {
            throw new HttpException('__Host- cookies require Secure, Path=/ and no Domain attribute.');
        }
    }

    public function toHeaderValue(): string
    {
        $parts = [$this->name . '=' . rawurlencode($this->value)];

        if ($this->expires !== null) {
            $utc = $this->expires->setTimezone(new DateTimeZone('GMT'));
            $parts[] = 'Expires=' . $utc->format(DateTimeInterface::RFC7231);
        }

        if ($this->maxAge !== null) {
            $parts[] = 'Max-Age=' . $this->maxAge;
        }

        if ($this->path !== '') {
            $parts[] = 'Path=' . $this->path;
        }

        if ($this->domain !== null && $this->domain !== '') {
            $parts[] = 'Domain=' . $this->domain;
        }

        if ($this->secure) {
            $parts[] = 'Secure';
        }

        if ($this->httpOnly) {
            $parts[] = 'HttpOnly';
        }

        $parts[] = 'SameSite=' . $this->sameSite->value;

        return implode('; ', $parts);
    }

    private function assertCookieValue(string $value): void
    {
        if (str_contains($value, "\r") || str_contains($value, "\n") || str_contains($value, "\0")) {
            throw new HttpException('Cookie value may not contain CR, LF or NUL characters.');
        }
    }

    private function assertPath(string $path): void
    {
        if (str_contains($path, ';') || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            throw new HttpException('Cookie Path contains unsupported delimiter or control characters.');
        }
    }

    private function assertDomain(?string $domain): void
    {
        if ($domain === null || $domain === '') {
            return;
        }

        if (
            str_contains($domain, ';')
            || str_contains($domain, ',')
            || preg_match('/[\x00-\x20\x7F]/', $domain) === 1
            || preg_match('/^\.?[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$/D', $domain) !== 1
            || str_contains($domain, '..')
        ) {
            throw new HttpException('Cookie Domain is invalid.');
        }
    }
}
