<?php

declare(strict_types=1);

namespace Forwext\Core\Promotion;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

interface PromotionMetricProvider
{
    public function metric(EntityId $userId, PromotionRuleType $ruleType, DateTimeImmutable $now): int;
}
