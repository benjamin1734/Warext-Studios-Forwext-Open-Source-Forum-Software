<?php

declare(strict_types=1);

namespace Forwext\Core\Seo\Discovery;

use InvalidArgumentException;

final readonly class PublicDiscoveryService
{
    /** @param iterable<PublicDiscoverySource> $sources */
    public function __construct(private iterable $sources)
    {
    }

    /** @return list<PublicDiscoveryEntry> */
    public function sitemapEntries(int $limit = 50000): array
    {
        if ($limit < 1 || $limit > 50000) {
            throw new InvalidArgumentException('Sitemap entry limit must be 1..50000.');
        }

        $result = [];
        $seen = [];
        foreach ($this->sources as $source) {
            $remaining = $limit - count($result);
            if ($remaining < 1) {
                break;
            }
            foreach ($source->sitemapEntries($remaining) as $entry) {
                if (isset($seen[$entry->path])) {
                    continue;
                }
                $seen[$entry->path] = true;
                $result[] = $entry;
                if (count($result) >= $limit) {
                    break 2;
                }
            }
        }

        return $result;
    }

    /** @return list<PublicDiscoveryEntry> */
    public function feedEntries(int $limit = 50): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Feed entry limit must be 1..100.');
        }

        $result = [];
        $seen = [];
        foreach ($this->sources as $source) {
            foreach ($source->feedEntries($limit) as $entry) {
                if (isset($seen[$entry->path])) {
                    continue;
                }
                $seen[$entry->path] = true;
                $result[] = $entry;
            }
        }

        usort(
            $result,
            static fn (PublicDiscoveryEntry $left, PublicDiscoveryEntry $right): int =>
                ($right->updatedAt?->getTimestamp() ?? 0) <=> ($left->updatedAt?->getTimestamp() ?? 0),
        );

        return array_slice($result, 0, $limit);
    }
}
