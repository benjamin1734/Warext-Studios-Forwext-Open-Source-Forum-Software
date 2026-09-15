<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Mfa\Challenge;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class MfaChallengeGrant
{
    public function __construct(public EntityId $userId, public string $deviceId, public int $credentialVersion, public MfaChallengePurpose $purpose, public ?string $actionKey, public bool $rememberRequested, public DateTimeImmutable $expiresAt)
    {
    }
}
