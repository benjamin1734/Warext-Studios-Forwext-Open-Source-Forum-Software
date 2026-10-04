<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Forum\Discovery;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Discovery\DatabaseForumPublicReader;
use PHPUnit\Framework\TestCase;

final class DatabaseForumPublicReaderTest extends TestCase
{
    public function testIndexSummariesOnlyCountVisibleActiveForumContent(): void
    {
        $database = new RecordingForumPublicQueryExecutor(
            rowBatches: [
                [[
                    'forum_node_id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                    'thread_count' => 2,
                    'post_count' => 7,
                ]],
                [[
                    'forum_node_id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                    'thread_id' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
                    'title' => 'Son konu',
                    'username' => 'tester',
                    'updated_at_utc' => '2026-09-27 12:00:00.000000',
                ]],
            ],
        );
        $reader = new DatabaseForumPublicReader($database);

        $result = $reader->forumSummaries([
            EntityId::fromString('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'),
        ]);

        self::assertSame(2, $result['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']['thread_count']);
        self::assertSame(7, $result['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']['post_count']);
        self::assertSame(
            'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            $result['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']['latest_thread_id'],
        );
        self::assertCount(2, $database->queries);
        foreach ($database->queries as $query) {
            self::assertStringContainsString("t.moderation_state='visible'", $query->sql);
            self::assertStringContainsString('t.deleted=0', $query->sql);
            self::assertSame(
                'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                $query->parameters['forum_0'] ?? null,
            );
        }
    }

    public function testPostListingIncludesPrimaryGroupWithoutExtraQueries(): void
    {
        $database = new RecordingForumPublicQueryExecutor(
            rowBatches: [[[
                'post_id' => 'cccccccccccccccccccccccccccccccc',
                'position' => 2,
                'body_source' => 'Yanıt',
                'created_at_utc' => '2026-10-04 11:00:00.000000',
                'updated_at_utc' => '2026-10-04 11:05:00.000000',
                'author_user_id' => 'dddddddddddddddddddddddddddddddd',
                'author_username' => 'author',
                'author_group_name' => 'Aktif Üye',
            ]]],
            values: [1],
        );
        $reader = new DatabaseForumPublicReader($database);

        $page = $reader->posts(
            EntityId::fromString('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'),
            1,
            20,
        );

        self::assertSame('Aktif Üye', $page['rows'][0]['author_group_name']);
        self::assertCount(2, $database->queries);
        self::assertStringContainsString(
            'LEFT JOIN forwext_user_primary_groups pg ON pg.user_id=p.author_user_id',
            $database->queries[1]->sql,
        );
        self::assertStringContainsString(
            'LEFT JOIN forwext_user_groups g ON g.group_id=pg.group_id',
            $database->queries[1]->sql,
        );
        self::assertStringContainsString('g.name AS author_group_name', $database->queries[1]->sql);
    }

    public function testThreadListingUsesBoundForumIdAndVisiblePosts(): void
    {
        $database = new RecordingForumPublicQueryExecutor(
            rowBatches: [[[
                'thread_id' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
                'title' => 'Üretim konusu',
                'sticky' => 1,
                'featured' => 0,
                'locked' => 0,
                'created_at_utc' => '2026-09-27 10:00:00.000000',
                'author_username' => 'author',
                'post_count' => 4,
                'last_post_at' => '2026-09-27 12:00:00.000000',
                'last_post_username' => 'reply',
            ]]],
            values: [1],
        );
        $reader = new DatabaseForumPublicReader($database);

        $page = $reader->threads(
            EntityId::fromString('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'),
            1,
            30,
        );

        self::assertSame(1, $page['total']);
        self::assertSame(4, $page['rows'][0]['post_count']);
        self::assertCount(2, $database->queries);
        self::assertSame(
            'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            $database->queries[0]->parameters['forum_node_id'] ?? null,
        );
        self::assertStringContainsString("p.moderation_state='visible'", $database->queries[1]->sql);
        self::assertStringContainsString('ORDER BY t.sticky DESC,t.featured DESC', $database->queries[1]->sql);
    }
}

final class RecordingForumPublicQueryExecutor implements QueryExecutor
{
    /** @var list<CompiledQuery> */
    public array $queries = [];

    /**
     * @param list<list<array<string,mixed>>> $rowBatches
     * @param list<mixed> $values
     */
    public function __construct(
        private array $rowBatches = [],
        private array $values = [],
    ) {
    }

    public function execute(CompiledQuery $query): int
    {
        $this->queries[] = $query;
        return 0;
    }

    public function fetchOne(CompiledQuery $query): ?array
    {
        $this->queries[] = $query;
        return null;
    }

    public function fetchAll(CompiledQuery $query): array
    {
        $this->queries[] = $query;
        return array_shift($this->rowBatches) ?? [];
    }

    public function fetchValue(CompiledQuery $query): mixed
    {
        $this->queries[] = $query;
        return array_shift($this->values);
    }
}
