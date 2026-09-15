<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Forum\Thread;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Thread\Thread;
use Forwext\Core\Forum\Thread\ThreadModerationState;
use Forwext\Core\Forum\Thread\ThreadTitle;
use Forwext\Core\Forum\Thread\ThreadTypeDefinition;
use Forwext\Core\Forum\Thread\ThreadTypeKey;
use Forwext\Core\Forum\Thread\ThreadTypeRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ThreadDomainTest extends TestCase
{
    public function testCoreTypeRegistryIsExtensibleButRejectsOverrides(): void
    {
        $registry = ThreadTypeRegistry::withCoreDefaults();
        $discussion = $registry->require(ThreadTypeKey::fromString('discussion'));

        self::assertTrue($discussion->isSystem());
        self::assertTrue($discussion->allowsReplies());

        $registry->register(new ThreadTypeDefinition(
            ThreadTypeKey::fromString('article'),
            'Article',
            false,
        ));
        self::assertFalse($registry->require(ThreadTypeKey::fromString('article'))->allowsReplies());

        $this->expectException(InvalidArgumentException::class);
        $registry->register(new ThreadTypeDefinition(
            ThreadTypeKey::fromString('discussion'),
            'Hijack',
        ));
    }

    public function testThreadCreationAndStateLifecycleRecordsEvents(): void
    {
        $now = $this->time('2026-09-15 18:30:00.000000');
        $thread = Thread::create(
            $this->id('a'),
            $this->id('b'),
            $this->id('1'),
            ThreadTypeKey::fromString('discussion'),
            ThreadTitle::fromString('Initial thread'),
            true,
            $now,
        );

        self::assertSame(ThreadModerationState::Pending, $thread->moderationState());
        self::assertFalse($thread->isLocked());
        self::assertFalse($thread->isSticky());
        self::assertFalse($thread->isFeatured());
        self::assertSame(0, $thread->version());

        $thread->lock($this->time('2026-09-15 18:31:00.000000'));
        $thread->stick($this->time('2026-09-15 18:32:00.000000'));
        $thread->feature($this->time('2026-09-15 18:33:00.000000'));
        $thread->approve($this->time('2026-09-15 18:34:00.000000'));
        $thread->rename(
            ThreadTitle::fromString('Renamed thread'),
            $this->time('2026-09-15 18:35:00.000000'),
        );

        self::assertTrue($thread->isLocked());
        self::assertTrue($thread->isSticky());
        self::assertTrue($thread->isFeatured());
        self::assertSame(ThreadModerationState::Visible, $thread->moderationState());
        self::assertSame('Renamed thread', $thread->title()->value());

        $events = $thread->releaseDomainEvents();
        self::assertSame([
            'thread.created',
            'thread.locked',
            'thread.stickied',
            'thread.featured',
            'thread.approved',
            'thread.title_changed',
        ], array_map(static fn ($event): string => $event->eventName(), $events));
    }

    public function testStateOperationsAreIdempotentAndModerationCanRejectVisibleContent(): void
    {
        $thread = Thread::create(
            $this->id('a'),
            $this->id('b'),
            $this->id('1'),
            ThreadTypeKey::fromString('discussion'),
            ThreadTitle::fromString('Visible thread'),
            false,
            $this->time('2026-09-15 18:30:00.000000'),
        );
        $thread->releaseDomainEvents();

        self::assertFalse($thread->unlock($this->time('2026-09-15 18:31:00.000000')));
        self::assertTrue($thread->reject($this->time('2026-09-15 18:32:00.000000')));
        self::assertSame(ThreadModerationState::Rejected, $thread->moderationState());
        self::assertFalse($thread->reject($this->time('2026-09-15 18:33:00.000000')));
        self::assertTrue($thread->requestModeration($this->time('2026-09-15 18:34:00.000000')));
        self::assertSame(ThreadModerationState::Pending, $thread->moderationState());
    }

    public function testMutationCannotTravelBeforeCreationTime(): void
    {
        $thread = Thread::create(
            $this->id('a'),
            $this->id('b'),
            $this->id('1'),
            ThreadTypeKey::fromString('discussion'),
            ThreadTitle::fromString('Thread'),
            false,
            $this->time('2026-09-15 18:30:00.000000'),
        );

        $this->expectException(InvalidArgumentException::class);
        $thread->lock($this->time('2026-09-15 18:29:59.000000'));
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
