<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Pipeline;

use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Moderation\Abuse\AbuseContext;
use Forwext\Core\Moderation\Abuse\AbuseEventType;
use InvalidArgumentException;

final class AbusePipelineAttributes
{
    private const IDENTITY = 'abuse.identity_fingerprint';
    private const IP = 'abuse.ip_fingerprint';
    private const DEVICE = 'abuse.device_fingerprint';

    /**
     * @return array<string, scalar|null>
     */
    public static function fromRequestContext(
        ?AbuseContext $context,
        AbuseEventType $expectedType,
        EntityId $actorUserId,
    ): array {
        if ($context === null) {
            return [];
        }
        if ($context->eventType !== $expectedType) {
            throw new InvalidArgumentException('Abuse request context event type does not match content pipeline operation.');
        }
        if ($context->actorUserId !== null && !$context->actorUserId->equals($actorUserId)) {
            throw new InvalidArgumentException('Abuse request context actor does not match content pipeline operation.');
        }

        return [
            self::IDENTITY => $context->identityFingerprint,
            self::IP => $context->ipFingerprint,
            self::DEVICE => $context->deviceFingerprint,
        ];
    }

    public static function identity(ContentPipelineContext $context): ?string
    {
        return self::fingerprint($context, self::IDENTITY);
    }

    public static function ip(ContentPipelineContext $context): ?string
    {
        return self::fingerprint($context, self::IP);
    }

    public static function device(ContentPipelineContext $context): ?string
    {
        return self::fingerprint($context, self::DEVICE);
    }

    private static function fingerprint(ContentPipelineContext $context, string $key): ?string
    {
        $value = $context->attributes[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Content pipeline abuse fingerprint is invalid.');
        }
        return $value;
    }
}
