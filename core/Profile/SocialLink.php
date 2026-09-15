<?php

declare(strict_types=1);
namespace Forwext\Core\Profile;
final readonly class SocialLink
{
    public function __construct(public string $key, public string $url, public ProfileVisibility $visibility = ProfileVisibility::Public, public int $sortOrder = 0)
    {
        if (preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $key) !== 1) { throw new ProfileException('Social link key is invalid.'); }
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new ProfileException('Social link must be an absolute HTTPS URL without credentials.');
        }
        if (strlen($url) > 2048 || $sortOrder < 0 || $sortOrder > 1000) { throw new ProfileException('Social link limits are invalid.'); }
    }
}
