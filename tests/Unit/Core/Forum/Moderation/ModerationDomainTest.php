<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Forum\Moderation;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Moderation\ModerationAuditContext;
use Forwext\Core\Forum\Moderation\ModerationReasonCode;
use Forwext\Core\Forum\Moderation\ModerationRequestId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ModerationDomainTest extends TestCase
{
    public function testReasonAndRequestIdsAreBoundedMachineIdentifiers(): void
    {
        self::assertSame('duplicate.thread', ModerationReasonCode::fromString(' DUPLICATE.THREAD ')->value());
        self::assertSame('req:123-abc', ModerationRequestId::fromString('req:123-abc')->value());

        foreach (['', 'contains space', "bad\nreason", str_repeat('a', 65)] as $invalid) {
            try {
                ModerationReasonCode::fromString($invalid);
                self::fail('Invalid moderation reason code must be rejected.');
            } catch (InvalidArgumentException) {
            }
        }

        foreach (['', 'contains space', "bad\rrequest", str_repeat('a', 101)] as $invalid) {
            try {
                ModerationRequestId::fromString($invalid);
                self::fail('Invalid moderation request id must be rejected.');
            } catch (InvalidArgumentException) {
            }
        }
    }

    public function testAuditContextUsesAuthenticatedActorAndNormalizesTimeToUtc(): void
    {
        $actor = EntityId::fromString(str_repeat('1', 32));
        $context = new ModerationAuditContext(
            $actor,
            ModerationReasonCode::fromString('review'),
            ModerationRequestId::fromString('req-utc'),
            new DateTimeImmutable('2026-09-16 00:30:00', new DateTimeZone('Europe/Istanbul')),
        );

        self::assertSame($actor->value(), $context->actorUserId->value());
        self::assertSame('UTC', $context->occurredAt->getTimezone()->getName());
        self::assertSame('2026-09-15 21:30:00', $context->occurredAt->format('Y-m-d H:i:s'));
    }
}
