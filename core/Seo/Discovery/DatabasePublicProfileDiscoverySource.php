<?php

declare(strict_types=1);

namespace Forwext\Core\Seo\Discovery;

final readonly class DatabasePublicProfileDiscoverySource implements PublicDiscoverySource
{
    public function __construct(private DatabasePublicProfileSeoReader $profiles)
    {
    }

    public function sitemapEntries(int $limit): array
    {
        return array_map(
            static fn (PublicProfileSeoRecord $profile): PublicDiscoveryEntry => new PublicDiscoveryEntry(
                $profile->canonicalPath(),
                $profile->username . ' · Forwext',
                'Herkese açık Forwext üye profili',
                $profile->updatedAt,
            ),
            $this->profiles->latest($limit),
        );
    }

    public function feedEntries(int $limit): array
    {
        return array_map(
            static fn (PublicProfileSeoRecord $profile): PublicDiscoveryEntry => new PublicDiscoveryEntry(
                $profile->canonicalPath(),
                $profile->username,
                'Yeni veya güncellenmiş herkese açık Forwext üye profili.',
                $profile->updatedAt,
            ),
            $this->profiles->latest($limit),
        );
    }
}
