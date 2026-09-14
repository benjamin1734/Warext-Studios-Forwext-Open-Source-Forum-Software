<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Domain\User;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\User\EmailAddress;
use Forwext\Core\Domain\User\User;
use Forwext\Core\Domain\User\UserCustomFieldKey;
use Forwext\Core\Domain\User\UserCustomFieldValue;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Domain\User\UserLocale;
use Forwext\Core\Domain\User\Username;
use Forwext\Core\Domain\User\UserStatus;
use Forwext\Core\Domain\User\UserStatusTransitionException;
use Forwext\Core\Domain\User\UserTimezone;
use PHPUnit\Framework\TestCase;

final class UserLifecycleTest extends TestCase
{
    public function testCreationMutationsAndHistoryDoNotCopySensitiveOldValues(): void
    {
        $now = new DateTimeImmutable('2026-09-14 20:00:00', new DateTimeZone('UTC'));
        $user = $this->user($now);

        self::assertSame(0, $user->version());
        self::assertSame(UserStatus::PendingEmailVerification, $user->status());
        self::assertSame('user.created', $user->pendingHistory()[0]->eventType);

        $later = $now->add(new DateInterval('PT1M'));
        self::assertTrue($user->changeEmail(EmailAddress::fromString('new@example.com'), $later, $user->id()));
        self::assertTrue($user->changeStatus(UserStatus::Active, $later, actorId: $user->id(), reasonCode: 'email.verified'));
        self::assertTrue($user->setCustomField(
            UserCustomFieldKey::fromString('profile.favorite_game'),
            UserCustomFieldValue::string('Minecraft'),
            $later,
            $user->id(),
        ));

        $history = $user->pendingHistory();
        self::assertCount(4, $history);
        self::assertSame(['email'], $history[1]->changedFields);
        self::assertSame(UserStatus::PendingEmailVerification, $history[2]->fromStatus);
        self::assertSame(UserStatus::Active, $history[2]->toStatus);
        self::assertSame('email.verified', $history[2]->reasonCode);
        self::assertStringNotContainsString('old@example.com', json_encode($history, JSON_THROW_ON_ERROR));
    }

    public function testIllegalStateTransitionFailsClosed(): void
    {
        $user = $this->user(new DateTimeImmutable('2026-09-14 20:00:00', new DateTimeZone('UTC')));

        $this->expectException(UserStatusTransitionException::class);
        $user->changeStatus(
            UserStatus::Banned,
            new DateTimeImmutable('2026-09-14 20:01:00', new DateTimeZone('UTC')),
        );
    }

    public function testPersistedVersionAdvancesExactlyOnceAndClearsPendingHistory(): void
    {
        $user = $this->user(new DateTimeImmutable('2026-09-14 20:00:00', new DateTimeZone('UTC')));

        $user->markPersisted(1);

        self::assertSame(1, $user->version());
        self::assertSame([], $user->pendingHistory());
        self::assertNotEmpty($user->releaseDomainEvents());
    }

    private function user(DateTimeImmutable $now): User
    {
        return User::create(
            UserId::generate(),
            Username::fromString('old_user'),
            EmailAddress::fromString('old@example.com'),
            UserStatus::PendingEmailVerification,
            UserLocale::fromString('tr-TR'),
            UserTimezone::fromString('Europe/Istanbul'),
            $now,
        );
    }
}
