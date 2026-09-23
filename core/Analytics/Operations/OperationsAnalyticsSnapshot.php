<?php

declare(strict_types=1);

namespace Forwext\Core\Analytics\Operations;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class OperationsAnalyticsSnapshot
{
    public DateTimeImmutable $generatedAt;

    /**
     * @param list<array{key:string,label:string,total:int,active:int,resolved:int,rejected:int,duplicate:int}> $bugCategories
     * @param list<array{id:string,username:string,active_reports:int,active_support:int,active_bugs:int,discipline_actions:int,audit_actions:int,total:int}> $staffWorkload
     */
    public function __construct(
        public int $windowDays,
        public int $reportVolume,
        public int $reportGroupsOpened,
        public int $reportResolved,
        public int $reportRejected,
        public ?float $reportAvgResolutionSeconds,
        public int $warningCount,
        public int $restrictionCount,
        public int $suspensionCount,
        public int $banCount,
        public int $supportCreated,
        public int $supportResolved,
        public int $supportClosed,
        public int $supportFirstResponseSlaBreaches,
        public int $supportResolutionSlaBreaches,
        public ?float $supportAvgFirstResponseSeconds,
        public ?float $supportAvgResolutionSeconds,
        public int $bugCreated,
        public int $bugResolved,
        public int $bugRejected,
        public int $bugDuplicate,
        public ?float $bugAvgFinalizationSeconds,
        public array $bugCategories,
        public array $staffWorkload,
        DateTimeImmutable $generatedAt,
    ) {
        if (!in_array($this->windowDays, [7, 30, 90], true)) {
            throw new InvalidArgumentException('Operations analytics range must be 7, 30 or 90 days.');
        }

        foreach ([
            $this->reportVolume,
            $this->reportGroupsOpened,
            $this->reportResolved,
            $this->reportRejected,
            $this->warningCount,
            $this->restrictionCount,
            $this->suspensionCount,
            $this->banCount,
            $this->supportCreated,
            $this->supportResolved,
            $this->supportClosed,
            $this->supportFirstResponseSlaBreaches,
            $this->supportResolutionSlaBreaches,
            $this->bugCreated,
            $this->bugResolved,
            $this->bugRejected,
            $this->bugDuplicate,
        ] as $value) {
            if ($value < 0) {
                throw new InvalidArgumentException('Operations analytics count cannot be negative.');
            }
        }

        foreach ([
            $this->reportAvgResolutionSeconds,
            $this->supportAvgFirstResponseSeconds,
            $this->supportAvgResolutionSeconds,
            $this->bugAvgFinalizationSeconds,
        ] as $duration) {
            if ($duration !== null && $duration < 0.0) {
                throw new InvalidArgumentException('Operations analytics duration cannot be negative.');
            }
        }

        foreach ($this->bugCategories as $row) {
            foreach ([
                $row['total'],
                $row['active'],
                $row['resolved'],
                $row['rejected'],
                $row['duplicate'],
            ] as $value) {
                if ($value < 0) {
                    throw new InvalidArgumentException('Bug category analytics count cannot be negative.');
                }
            }
        }
        foreach ($this->staffWorkload as $row) {
            foreach ([
                $row['active_reports'],
                $row['active_support'],
                $row['active_bugs'],
                $row['discipline_actions'],
                $row['audit_actions'],
                $row['total'],
            ] as $value) {
                if ($value < 0) {
                    throw new InvalidArgumentException('Staff workload analytics count cannot be negative.');
                }
            }
        }

        $this->generatedAt = $generatedAt->setTimezone(new DateTimeZone('UTC'));
    }
}
