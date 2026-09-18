<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Abuse;

use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final class AbuseContentContext
{
    public static function thread(EntityId $actorUserId, string $title, ?AbuseContext $requestContext = null): AbuseContext
    {
        return self::merge(
            AbuseEventType::Thread,
            $actorUserId,
            hash('sha256', 'thread:' . self::normalize($title)),
            $requestContext,
        );
    }

    public static function post(EntityId $actorUserId, string $body, ?AbuseContext $requestContext = null): AbuseContext
    {
        return self::merge(
            AbuseEventType::Post,
            $actorUserId,
            hash('sha256', 'post:' . self::normalize($body)),
            $requestContext,
        );
    }

    private static function merge(
        AbuseEventType $type,
        EntityId $actorUserId,
        string $contentFingerprint,
        ?AbuseContext $requestContext,
    ): AbuseContext {
        if ($requestContext !== null) {
            if ($requestContext->eventType !== $type) {
                throw new InvalidArgumentException('Abuse request context event type does not match content operation.');
            }
            if ($requestContext->actorUserId !== null && !$requestContext->actorUserId->equals($actorUserId)) {
                throw new InvalidArgumentException('Abuse request context actor does not match content operation.');
            }
        }

        return new AbuseContext(
            $type,
            $actorUserId,
            $requestContext?->identityFingerprint,
            $requestContext?->ipFingerprint,
            $requestContext?->deviceFingerprint,
            $contentFingerprint,
        );
    }

    private static function normalize(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
        return strtolower($value);
    }
}
