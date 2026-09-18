<?php
declare(strict_types=1);
namespace Forwext\Core\Support\Reporting;
final readonly class SupportDashboardSummary {
    public function __construct(
        public int $total,
        public int $active,
        public int $resolved,
        public int $closed,
        public int $firstResponseBreaches,
        public int $resolutionBreaches,
        public ?float $averageFirstResponseSeconds,
        public ?float $averageResolutionSeconds,
    ) {}
}
