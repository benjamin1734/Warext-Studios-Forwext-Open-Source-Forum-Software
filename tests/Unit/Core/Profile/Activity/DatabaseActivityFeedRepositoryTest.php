<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Profile\Activity;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use Forwext\Core\Profile\Activity\DatabaseActivityFeedRepository;
use PHPUnit\Framework\TestCase;

final class DatabaseActivityFeedRepositoryTest extends TestCase
{
    public function testFeedUnionContainsOnlyPublicActivitySourcesAndNeverBookmarks(): void
    {
        $database = new FeedRecordingDatabase();
        (new DatabaseActivityFeedRepository($database))->candidates(25, 5);

        self::assertCount(1, $database->fetchAllQueries);
        $sql = $database->fetchAllQueries[0]->sql;
        self::assertStringContainsString('forwext_threads', $sql);
        self::assertStringContainsString('forwext_posts', $sql);
        self::assertStringContainsString('forwext_profile_posts', $sql);
        self::assertStringContainsString('forwext_profile_comments', $sql);
        self::assertStringContainsString('forwext_profile_post_reactions', $sql);
        self::assertStringNotContainsString('forwext_post_bookmarks', $sql);
        self::assertStringContainsString('LIMIT 25 OFFSET 5', $sql);
    }
}

final class FeedRecordingDatabase implements QueryExecutor
{
    /** @var list<CompiledQuery> */ public array $fetchAllQueries = [];
    public function execute(CompiledQuery $query): int { return 0; }
    public function fetchOne(CompiledQuery $query): ?array { return null; }
    public function fetchAll(CompiledQuery $query): array { $this->fetchAllQueries[] = $query; return []; }
    public function fetchValue(CompiledQuery $query): mixed { return 0; }
}
