<?php

declare(strict_types=1);

namespace Forwext\Core\Install;

use InvalidArgumentException;
use SensitiveParameter;

final readonly class InstallationInput
{
    public function __construct(
        public string $canonicalUrl,
        public string $databaseHost,
        public int $databasePort,
        public string $databaseName,
        public string $databaseUsername,
        #[SensitiveParameter] public string $databasePassword,
    ) {
        $parts = parse_url($canonicalUrl);
        if (!is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])
        ) {
            throw new InvalidArgumentException('Canonical URL must be an absolute HTTP(S) origin/path without credentials, query or fragment.');
        }

        if ($databasePort < 1 || $databasePort > 65535) {
            throw new InvalidArgumentException('Database port is outside the valid range.');
        }
    }

    public function normalizedCanonicalUrl(): string
    {
        return rtrim($this->canonicalUrl, '/');
    }

    public function relyingPartyId(): string
    {
        $host = parse_url($this->canonicalUrl, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw new InvalidArgumentException('Canonical URL hostname is unavailable.');
        }

        return strtolower($host);
    }
}
