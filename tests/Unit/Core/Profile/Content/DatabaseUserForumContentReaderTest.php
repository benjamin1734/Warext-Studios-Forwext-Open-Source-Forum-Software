<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Profile\Content;

use Closure;
use DateTimeImmutable;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Profile\Content\DatabaseUserForumContentReader;
use Forwext\Core\Profile\Content\UserForumContentType;
use Forwext\Core\Search\Access\SearchAccessScopeProvider;
use PHPUnit\Framework\TestCase;

final class DatabaseUserForumContentReaderTest extends TestCase
{
    public function testThreadsUseViewerForumScopesAndTargetAuthorOnly(): void
    {
        $database = new UserContentRecordingDatabase([[
            'thread_id' => str_repeat('a', 32),
            'forum_node_id' => str_repeat('1', 32),
            'thread_title' => 'Visible thread',
            'forum_title' => 'General',
            'created_at_utc' => '2026-10-04 10:00:00.000000',
            'updated_at_utc' => '2026-10-04 11:00:00.000000',
        ]]);
        $reader = new DatabaseUserForumContentReader(
            $database,
            new FixedUserContentScopes([
                'public',
                'forum.node:' . str_repeat('2', 32),
                'forum.node:' . str_repeat('1', 32),
                'forum.node:' . str_repeat('1', 32),
                'forum.node:not-an-id',
            ]),
        );
        $viewer = EntityId::fromString(str_repeat('9', 32));
        $target = EntityId::fromString(str_repeat('8', 32));

        $items = $reader->threads($viewer, $target, 21, 20);

        self::assertCount(1, $items);
        self::assertSame(UserForumContentType::Thread, $items[0]->type);
        self::assertSame('Visible thread', $items[0]->threadTitle);
        self::assertNotNull($database->query);
        self::assertStringContainsString('t.`author_user_id` = :target_user_id', $database->query->sql);
        self::assertStringContainsString("t.`moderation_state` = 'visible'", $database->query->sql);
        self::assertStringContainsString('t.`forum_node_id` IN (:forum_0,:forum_1)', $database->query->sql);
        self::assertStringContainsString('LIMIT 21 OFFSET 20', $database->query->sql);
        self::assertSame($target->value(), $database->query->parameters['target_user_id'] ?? null);
        self::assertSame(str_repeat('1', 32), $database->query->parameters['forum_0'] ?? null);
        self::assertSame(str_repeat('2', 32), $database->query->parameters['forum_1'] ?? null);
    }

    public function testPostsAreVisibleOnlyAndLinkableByThreadPosition(): void
    {
        $database = new UserContentRecordingDatabase([[
            'post_id' => str_repeat('b', 32),
            'thread_id' => str_repeat('a', 32),
            'position' => 7,
            'excerpt' => '<unsafe> body',
            'created_at_utc' => '2026-10-04 10:00:00.000000',
            'updated_at_utc' => '2026-10-04 11:00:00.000000',
            'forum_node_id' => str_repeat('1', 32),
            'thread_title' => 'Thread title',
            'forum_title' => 'General',
        ]]);
        $reader = new DatabaseUserForumContentReader(
            $database,
            new FixedUserContentScopes(['forum.node:' . str_repeat('1', 32)]),
        );

        $items = $reader->posts(
            EntityId::fromString(str_repeat('9', 32)),
            EntityId::fromString(str_repeat('8', 32)),
        );

        self::assertCount(1, $items);
        self::assertSame(UserForumContentType::Post, $items[0]->type);
        self::assertSame(7, $items[0]->position);
        self::assertSame('<unsafe> body', $items[0]->excerpt);
        self::assertNotNull($database->query);
        self::assertStringContainsString("p.`moderation_state` = 'visible'", $database->query->sql);
        self::assertStringContainsString("t.`moderation_state` = 'visible'", $database->query->sql);
        self::assertStringContainsString('LEFT(p.`body_source`, 600)', $database->query->sql);
    }

    public function testNoVisibleForumScopeSkipsDatabaseQuery(): void
    {
        $database = new UserContentRecordingDatabase([]);
        $reader = new DatabaseUserForumContentReader(
            $database,
            new FixedUserContentScopes(['public']),
        );

        self::assertSame([], $reader->threads(
            EntityId::fromString(str_repeat('9', 32)),
            EntityId::fromString(str_repeat('8', 32)),
        ));
        self::assertNull($database->query);
    }
}

final readonly class FixedUserContentScopes implements SearchAccessScopeProvider
{
    /** @param list<string> $scopes */
    public function __construct(private array $scopes)
    {
    }

    public function scopes(EntityId $userId): array
    {
        unset($userId);
        return $this->scopes;
    }
}

final class UserContentRecordingDatabase implements QueryExecutor
{
    public ?CompiledQuery $query = null;

    /** @param list<array<string,mixed>> $rows */
    public function __construct(private array $rows)
    {
    }

    public function execute(CompiledQuery $query): int
    {
        $this->query = $query;
        return 0;
    }

    public function fetchOne(CompiledQuery $query): ?array
    {
        $this->query = $query;
        return null;
    }

    public function fetchAll(CompiledQuery $query): array
    {
        $this->query = $query;
        return $this->rows;
    }

    public function fetchValue(CompiledQuery $query): mixed
    {
        $this->query = $query;
        return null;
    }
}
