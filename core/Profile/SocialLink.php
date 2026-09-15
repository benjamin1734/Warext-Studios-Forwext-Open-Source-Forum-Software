<?php

declare(strict_types=1);

namespace Forwext\Core\Profile;

final readonly class SocialLink
{
    private const ALLOWED_KEYS = [
        'discord',
        'facebook',
        'github',
        'instagram',
        'linkedin',
        'mastodon',
        'twitch',
        'x',
        'youtube',
    ];

    public function __construct(
        public string $key,
        public string $url,
        public ProfileVisibility $visibility = ProfileVisibility::Public,
        public int $sortOrder = 0,
    ) {
        if (!in_array($key, self::ALLOWED_KEYS, true)) {
            throw new ProfileException('Social link provider is not supported.');
        }

        $parts = parse_url($url);
        if (
            !is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            throw new ProfileException('Social link must be an absolute HTTPS URL without credentials.');
        }

        if (strlen($url) > 2048 || $sortOrder < 0 || $sortOrder > 1000) {
            throw new ProfileException('Social link limits are invalid.');
        }
    }
}
