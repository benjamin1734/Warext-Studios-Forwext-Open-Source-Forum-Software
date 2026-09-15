<?php

declare(strict_types=1);

namespace Forwext\Core\Profile\Music;

use Forwext\Core\Profile\ProfileException;

final readonly class ProfileMusicExternalPolicy
{
    /** @var list<string> */
    private array $allowedHosts;

    /** @param list<string> $allowedHosts */
    public function __construct(array $allowedHosts = [])
    {
        $normalized = [];
        foreach ($allowedHosts as $host) {
            if (!is_string($host)) {
                throw new ProfileException('Profile music external host allowlist contains an invalid entry.');
            }
            $host = self::normalizeHost($host);
            $normalized[$host] = true;
        }
        $this->allowedHosts = array_keys($normalized);
    }

    public function normalize(string $url): string
    {
        $url = trim($url);
        if (
            $url === ''
            || strlen($url) > 2048
            || preg_match('/[\x00-\x20\x7F]/', $url) === 1
            || filter_var($url, FILTER_VALIDATE_URL) === false
        ) {
            throw new ProfileException('External profile music URL is invalid.');
        }

        $parts = parse_url($url);
        if (
            !is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !is_string($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
        ) {
            throw new ProfileException('External profile music must use a credential-free HTTPS URL on the default port.');
        }

        $host = self::normalizeHost($parts['host']);
        if (!in_array($host, $this->allowedHosts, true)) {
            throw new ProfileException('External profile music host is not allowlisted.');
        }

        $path = is_string($parts['path'] ?? null) && $parts['path'] !== '' ? $parts['path'] : '/';
        $query = isset($parts['query']) && is_string($parts['query']) ? '?' . $parts['query'] : '';
        return 'https://' . $host . $path . $query;
    }

    /** @return list<string> */
    public function allowedHosts(): array
    {
        return $this->allowedHosts;
    }

    private static function normalizeHost(string $host): string
    {
        $host = strtolower(rtrim(trim($host), '.'));
        if (
            $host === ''
            || strlen($host) > 253
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || $host === 'localhost'
            || !str_contains($host, '.')
        ) {
            throw new ProfileException('Profile music external host must be a public DNS hostname.');
        }

        $labels = explode('.', $host);
        foreach ($labels as $label) {
            if (
                $label === ''
                || strlen($label) > 63
                || preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/D', $label) !== 1
            ) {
                throw new ProfileException('Profile music external host contains an invalid DNS label.');
            }
        }
        return $host;
    }
}
