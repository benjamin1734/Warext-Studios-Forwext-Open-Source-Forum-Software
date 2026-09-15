<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Forum\State;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\State\ContentDraft;
use Forwext\Core\Forum\State\DraftTargetType;
use Forwext\Core\Forum\State\SubscriptionPreferences;
use Forwext\Core\Forum\State\WatchNotificationMode;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DiscussionStateDomainTest extends TestCase
{
    public function testReplyDraftRejectsThreadTitle(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ContentDraft(
            $this->id('1'),
            DraftTargetType::Reply,
            $this->id('b'),
            'Not allowed',
            'Body',
            1,
            $this->time('2026-09-15 22:00:00.000000'),
        );
    }

    public function testDraftAcceptsEmptyAutosaveButRejectsUnsafeControlCharacters(): void
    {
        $draft = new ContentDraft(
            $this->id('1'),
            DraftTargetType::NewThread,
            $this->id('a'),
            '',
            '',
            1,
            $this->time('2026-09-15 22:00:00.000000'),
        );
        self::assertSame('', $draft->bodySource());

        $this->expectException(InvalidArgumentException::class);
        new ContentDraft(
            $this->id('1'),
            DraftTargetType::NewThread,
            $this->id('a'),
            null,
            "unsafe\x00body",
            1,
            $this->time('2026-09-15 22:00:00.000000'),
        );
    }

    public function testSubscriptionDefaultsAreSafeAndInAppFirst(): void
    {
        $preferences = new SubscriptionPreferences();
        self::assertTrue($preferences->autoWatchCreatedThreads());
        self::assertFalse($preferences->autoWatchRepliedThreads());
        self::assertSame(WatchNotificationMode::InApp, $preferences->defaultThreadMode());
        self::assertSame(WatchNotificationMode::InApp, $preferences->defaultForumMode());
    }

    private function id(string $seed): EntityId
    {
        return EntityId::fromString(str_repeat($seed, 32));
    }

    private function time(string $value): DateTimeImmutable
    {
        $time = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        self::assertInstanceOf(DateTimeImmutable::class, $time);
        return $time;
    }
}
