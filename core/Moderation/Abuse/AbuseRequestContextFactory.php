<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Abuse;

use Forwext\Core\Auth\AuthenticationFingerprint;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;

final readonly class AbuseRequestContextFactory
{
    public function __construct(private AuthenticationFingerprint $fingerprint)
    {
    }

    public function thread(EntityId $actorUserId, string $clientIp, string $userAgent): AbuseContext
    {
        return $this->content(AbuseEventType::Thread, $actorUserId, $clientIp, $userAgent);
    }

    public function post(EntityId $actorUserId, string $clientIp, string $userAgent): AbuseContext
    {
        return $this->content(AbuseEventType::Post, $actorUserId, $clientIp, $userAgent);
    }

    private function content(
        AbuseEventType $eventType,
        EntityId $actorUserId,
        string $clientIp,
        string $userAgent,
    ): AbuseContext {
        UserId::assert($actorUserId);
        return new AbuseContext(
            $eventType,
            $actorUserId,
            null,
            $this->fingerprint->ip($clientIp),
            $this->fingerprint->userAgent($userAgent),
        );
    }
}
