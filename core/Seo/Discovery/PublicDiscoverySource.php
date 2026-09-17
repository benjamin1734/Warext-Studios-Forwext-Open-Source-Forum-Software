<?php

declare(strict_types=1);

namespace Forwext\Core\Seo\Discovery;

interface PublicDiscoverySource
{
    /** @return list<PublicDiscoveryEntry> */
    public function sitemapEntries(int $limit): array;

    /** @return list<PublicDiscoveryEntry> */
    public function feedEntries(int $limit): array;
}
