<?php

declare(strict_types=1);

namespace Forwext\Core\Trophy;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

interface TrophyMetricProvider
{
    public function metric(EntityId $userId, TrophyRuleType $ruleType, DateTimeImmutable $now): int;
}
