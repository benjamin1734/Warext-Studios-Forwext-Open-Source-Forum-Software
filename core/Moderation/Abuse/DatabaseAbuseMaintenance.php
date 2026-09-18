<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Abuse;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use InvalidArgumentException;

final readonly class DatabaseAbuseMaintenance implements AbuseMaintenance
{
    public function __construct(private QueryExecutor $database)
    {
    }

    public function cleanupCounters(DateTimeImmutable $before, int $limit = 1000): int
    {
        self::assertLimit($limit, 5000);
        return $this->database->execute(new CompiledQuery(
            'DELETE FROM forwext_abuse_counters WHERE bucket_start_utc < :before '
            . 'ORDER BY bucket_start_utc ASC LIMIT ' . $limit,
            ['before' => self::format($before)],
        ));
    }

    public function cleanupResolvedEvents(DateTimeImmutable $before, int $limit = 500): int
    {
        self::assertLimit($limit, 2000);
        return $this->database->execute(new CompiledQuery(
            'DELETE FROM forwext_abuse_events WHERE resolved_at_utc IS NOT NULL AND resolved_at_utc < :before '
            . 'ORDER BY resolved_at_utc ASC LIMIT ' . $limit,
            ['before' => self::format($before)],
        ));
    }

    private static function assertLimit(int $limit, int $maximum): void
    {
        if ($limit < 1 || $limit > $maximum) {
            throw new InvalidArgumentException('Abuse maintenance batch limit is outside the supported range.');
        }
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
