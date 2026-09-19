<?php

declare(strict_types=1);

namespace Forwext\Core\Reward;

final readonly class RewardProviderResult
{
    public function __construct(public bool $createdAssignment)
    {
    }
}
