<?php
declare(strict_types=1);
namespace Forwext\Core\Support\Reporting;
final readonly class SupportCategoryMetric {
    public function __construct(
        public string $categoryKey,
        public string $categoryLabel,
        public int $total,
        public int $active,
        public int $resolvedOrClosed,
        public int $firstResponseBreaches,
        public int $resolutionBreaches,
        public ?float $averageFirstResponseSeconds,
        public ?float $averageResolutionSeconds,
    ) {}
}
