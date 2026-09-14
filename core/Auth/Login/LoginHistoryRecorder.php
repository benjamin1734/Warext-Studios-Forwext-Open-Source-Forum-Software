<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Login;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

interface LoginHistoryRecorder
{
    public function record(
        ?EntityId $userId,
        string $identityFingerprint,
        string $ipFingerprint,
        string $deviceFingerprint,
        LoginOutcome $outcome,
        DateTimeImmutable $occurredAt,
    ): void;
}
