<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Forum\Post;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Post\Post;
use Forwext\Core\Forum\Post\PostBody;
use Forwext\Core\Forum\Post\PostModerationState;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PostDomainTest extends TestCase
{
    public function testFirstPostIdentityAndModeratedCreation(): void
    {
        $post = Post::create(
            $this->id('a'),
            $this->id('b'),
            $this->id('1'),
            1,
            PostBody::fromString('First post body'),
            true,
            $this->time('2026-09-15 19:00:00.000000'),
        );

        self::assertTrue($post->isFirstPost());
        self::assertSame(PostModerationState::Pending, $post->moderationState());
        self::assertFalse($post->isDeleted());
        self::assertSame(0, $post->version());
    }

    public function testEditDeleteRestoreAndModerationPreserveHistorySnapshots(): void
    {
        $post = Post::hydrate(
            $this->id('a'),
            $this->id('b'),
            $this->id('1'),
            2,
            PostBody::fromString('Original'),
            PostModerationState::Pending,
            false,
            null,
            $this->time('2026-09-15 19:00:00.000000'),
            $this->time('2026-09-15 19:00:00.000000'),
            3,
        );
        $actor = $this->id('1');

        $post->edit(PostBody::fromString('Edited'), $actor, $this->time('2026-09-15 19:01:00.000000'));
        $post->delete($actor, $this->time('2026-09-15 19:02:00.000000'));
        $post->restore($actor, $this->time('2026-09-15 19:03:00.000000'));
        $post->approve($actor, $this->time('2026-09-15 19:04:00.000000'));

        self::assertSame('Edited', $post->body()->source());
        self::assertFalse($post->isDeleted());
        self::assertSame(PostModerationState::Visible, $post->moderationState());
        $history = $post->pendingHistory();
        self::assertCount(4, $history);
        self::assertSame(['edited', 'deleted', 'restored', 'approved'], array_map(
            static fn ($entry): string => $entry->action,
            $history,
        ));
        self::assertSame('Original', $history[0]->body->source());
        self::assertFalse($history[0]->deleted);
        self::assertTrue($history[2]->deleted);
        self::assertSame(PostModerationState::Pending, $history[3]->moderationState);
    }

    public function testRestoreDoesNotImplicitlyChangeModerationState(): void
    {
        $post = Post::hydrate(
            $this->id('a'),
            $this->id('b'),
            $this->id('1'),
            2,
            PostBody::fromString('Rejected'),
            PostModerationState::Rejected,
            true,
            $this->time('2026-09-15 19:01:00.000000'),
            $this->time('2026-09-15 19:00:00.000000'),
            $this->time('2026-09-15 19:01:00.000000'),
            4,
        );

        $post->restore($this->id('2'), $this->time('2026-09-15 19:02:00.000000'));

        self::assertFalse($post->isDeleted());
        self::assertSame(PostModerationState::Rejected, $post->moderationState());
    }

    public function testMutationTimeCannotMoveBackwards(): void
    {
        $post = Post::hydrate(
            $this->id('a'),
            $this->id('b'),
            $this->id('1'),
            2,
            PostBody::fromString('Body'),
            PostModerationState::Visible,
            false,
            null,
            $this->time('2026-09-15 19:00:00.000000'),
            $this->time('2026-09-15 19:05:00.000000'),
            2,
        );

        $this->expectException(InvalidArgumentException::class);
        $post->delete($this->id('1'), $this->time('2026-09-15 19:04:00.000000'));
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
