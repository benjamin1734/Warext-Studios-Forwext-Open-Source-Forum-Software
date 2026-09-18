<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Abuse;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use RuntimeException;

final readonly class AbuseMaintenanceJobHandler
{
    public function __construct(private AbuseMaintenance $maintenance)
    {
    }

    public function jobType(): string
    {
        return AbuseMaintenanceTasks::CLEANUP_JOB_TYPE;
    }

    public function handle(string $payload, DateTimeImmutable $now): int
    {
        try {
            $decoded = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Abuse maintenance job payload is invalid JSON.', previous: $exception);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Abuse maintenance job payload must be an object.');
        }

        $counterDays = $decoded['counter_retention_days'] ?? 8;
        $eventDays = $decoded['event_retention_days'] ?? 180;
        $counterLimit = $decoded['counter_limit'] ?? 1000;
        $eventLimit = $decoded['event_limit'] ?? 500;

        foreach ([
            'counter_retention_days' => [$counterDays, 1, 365],
            'event_retention_days' => [$eventDays, 7, 3650],
            'counter_limit' => [$counterLimit, 1, 5000],
            'event_limit' => [$eventLimit, 1, 2000],
        ] as $name => [$value, $minimum, $maximum]) {
            if (!is_int($value) || $value < $minimum || $value > $maximum) {
                throw new RuntimeException('Abuse maintenance job field is invalid: ' . $name);
            }
        }

        $now = $now->setTimezone(new DateTimeZone('UTC'));
        $counterBefore = $now->modify('-' . $counterDays . ' days');
        $eventBefore = $now->modify('-' . $eventDays . ' days');

        return $this->maintenance->cleanupCounters($counterBefore, $counterLimit)
            + $this->maintenance->cleanupResolvedEvents($eventBefore, $eventLimit);
    }
}
