<?php

declare(strict_types=1);

namespace Forwext\Core\Faq\Seo;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use RuntimeException;

final readonly class DatabaseFaqSeoReader
{
    public function __construct(private QueryExecutor $database)
    {
    }

    public function find(string $language, string $slug): ?FaqSeoRecord
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT a.language,a.slug,a.question,a.answer,a.seo_title,a.seo_description,a.updated_at_utc '
            . 'FROM forwext_faq_articles a INNER JOIN forwext_faq_categories c ON c.category_key=a.category_key '
            . "WHERE a.language=:language AND a.slug=:slug AND a.active=1 AND c.active=1 "
            . "AND a.visibility='public' AND c.visibility='public' LIMIT 1",
            ['language'=>$language,'slug'=>$slug],
        ));
        if ($row === null) {
            return null;
        }
        foreach (['language','slug','question','answer','updated_at_utc'] as $key) {
            if (!is_string($row[$key] ?? null)) {
                throw new RuntimeException('Stored FAQ SEO row is invalid.');
            }
        }

        return new FaqSeoRecord(
            (string) $row['language'],
            (string) $row['slug'],
            (string) $row['question'],
            (string) $row['answer'],
            $row['seo_title'] === null ? null : (string) $row['seo_title'],
            $row['seo_description'] === null ? null : (string) $row['seo_description'],
            self::date((string) $row['updated_at_utc']),
        );
    }

    private static function date(string $value): DateTimeImmutable
    {
        foreach (['!Y-m-d H:i:s.u','!Y-m-d H:i:s'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));
            if ($date instanceof DateTimeImmutable) {
                return $date;
            }
        }
        throw new RuntimeException('Stored FAQ SEO timestamp is invalid.');
    }
}
