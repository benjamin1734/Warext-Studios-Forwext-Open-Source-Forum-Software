<?php

declare(strict_types=1);

namespace Forwext\Core\Http\Security\Headers;

final readonly class SecurityHeaderPolicy
{
    public function __construct(
        public string $contentSecurityPolicy = "default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'self'",
        public string $referrerPolicy = 'strict-origin-when-cross-origin',
        public string $permissionsPolicy = 'camera=(), microphone=(), geolocation=()',
        public int $hstsMaxAge = 31536000,
    ) {
        if ($hstsMaxAge < 0) {
            throw new \InvalidArgumentException('HSTS max-age may not be negative.');
        }
    }
}
