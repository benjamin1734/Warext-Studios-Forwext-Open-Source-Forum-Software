<?php

declare(strict_types=1);

namespace Forwext\Core\Faq\Seo;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use Forwext\Core\Seo\Discovery\PublicDiscoveryEntry;
use Forwext\Core\Seo\Discovery\PublicDiscoverySource;
use RuntimeException;

final readonly class DatabaseFaqPublicDiscoverySource implements PublicDiscoverySource
{
    public function __construct(private QueryExecutor $database)
    {
    }

    public function sitemapEntries(int $limit): array
    {
        return $this->entries($limit);
    }

    public function feedEntries(int $limit): array
    {
        return $this->entries($limit);
    }

    /** @return list<PublicDiscoveryEntry> */
    private function entries(int $limit): array
    {
        if ($limit < 1 || $limit > 50000) {
            throw new \InvalidArgumentException('FAQ discovery limit is invalid.');
        }
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT a.language,a.slug,a.question,a.seo_description,a.answer,a.updated_at_utc '
            . 'FROM forwext_faq_articles a INNER JOIN forwext_faq_categories c ON c.category_key=a.category_key '
            . "WHERE a.active=1 AND c.active=1 AND a.visibility='public' AND c.visibility='public' "
            . 'ORDER BY a.updated_at_utc DESC,a.article_id ASC LIMIT ' . $limit,
        ));
        $entries = [];
        foreach ($rows as $row) {
            foreach (['language','slug','question','answer','updated_at_utc'] as $key) {
                if (!is_string($row[$key] ?? null)) {
                    throw new RuntimeException('Stored FAQ discovery row is invalid.');
                }
            }
            $summary = is_string($row['seo_description'] ?? null) && $row['seo_description'] !== ''
                ? (string) $row['seo_description']
                : self::summary((string) $row['answer']);
            $entries[] = new PublicDiscoveryEntry(
                '/faq/' . rawurlencode((string) $row['language']) . '/' . rawurlencode((string) $row['slug']),
                (string) $row['question'],
                $summary,
                self::date((string) $row['updated_at_utc']),
            );
        }
        return $entries;
    }

    private static function summary(string $answer): string
    {
        $plain = trim((string) preg_replace('/\s+/u', ' ', strip_tags($answer)));
        return strlen($plain) <= 500 ? $plain : substr($plain, 0, 497) . '...';
    }

    private static function date(string $value): DateTimeImmutable
    {
        foreach (['!Y-m-d H:i:s.u','!Y-m-d H:i:s'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));
            if ($date instanceof DateTimeImmutable) {
                return $date;
            }
        }
        throw new RuntimeException('Stored FAQ discovery timestamp is invalid.');
    }
}
