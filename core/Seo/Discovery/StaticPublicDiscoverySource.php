<?php

declare(strict_types=1);

namespace Forwext\Core\Seo\Discovery;

final readonly class StaticPublicDiscoverySource implements PublicDiscoverySource
{
    public function sitemapEntries(int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        $entries = [
            new PublicDiscoveryEntry('/', 'Forwext', 'Forwext — Open Source Forum Platform'),
            new PublicDiscoveryEntry('/members', 'Üyeler', 'Herkese açık Forwext üye dizini'),
        ];

        return array_slice($entries, 0, $limit);
    }

    public function feedEntries(int $limit): array
    {
        return [];
    }
}
