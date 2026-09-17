<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Search;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use Forwext\Core\Search\Lifecycle\Source\DatabasePostSearchContentSource;
use Forwext\Core\Search\SearchAttribute;
use PHPUnit\Framework\TestCase;

final class SearchMetadataSourceTest extends TestCase
{
    public function testPostSourceBuildsPermissionNeutralFilterMetadataFromCanonicalRelations(): void
    {
        $database = new SearchMetadataSourceDatabase();
        $database->one = [
            [
                'post_id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                'author_user_id' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
                'body_source' => 'Visible searchable body',
                'moderation_state' => 'visible',
                'deleted' => 0,
                'updated_at_utc' => '2026-09-17 17:00:00.000001',
                'thread_id' => 'cccccccccccccccccccccccccccccccc',
                'thread_title' => 'Advanced search thread',
                'forum_node_id' => 'dddddddddddddddddddddddddddddddd',
                'type_key' => 'discussion',
                'thread_state' => 'visible',
                'thread_deleted' => 0,
                'merged_into_thread_id' => null,
                'node_visibility' => 'listed',
            ],
            ['prefix_id' => 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee'],
        ];
        $database->all = [[
            ['tag_id' => 'ffffffffffffffffffffffffffffffff'],
            ['tag_id' => '11111111111111111111111111111111'],
        ]];

        $document = (new DatabasePostSearchContentSource($database))
            ->document('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');

        self::assertNotNull($document);
        self::assertSame(['dddddddddddddddddddddddddddddddd'], $document->attributes[SearchAttribute::FORUM]);
        self::assertSame(['bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'], $document->attributes[SearchAttribute::USER]);
        self::assertSame(['eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee'], $document->attributes[SearchAttribute::PREFIX]);
        self::assertSame(
            ['ffffffffffffffffffffffffffffffff', '11111111111111111111111111111111'],
            $document->attributes[SearchAttribute::TAG],
        );
        self::assertSame(['visible'], $document->attributes[SearchAttribute::STATE]);
        self::assertSame(['discussion'], $document->attributes[SearchAttribute::THREAD_TYPE]);
        self::assertStringContainsString('forwext_thread_prefix_assignments', $database->queries[1]->sql);
        self::assertStringContainsString('forwext_thread_tags', $database->queries[2]->sql);
    }
}

final class SearchMetadataSourceDatabase implements QueryExecutor
{
    /** @var list<array<string, mixed>|null> */
    public array $one = [];
    /** @var list<list<array<string, mixed>>> */
    public array $all = [];
    /** @var list<CompiledQuery> */
    public array $queries = [];

    public function execute(CompiledQuery $query): int
    {
        $this->queries[] = $query;
        return 1;
    }

    public function fetchOne(CompiledQuery $query): ?array
    {
        $this->queries[] = $query;
        return array_shift($this->one);
    }

    public function fetchAll(CompiledQuery $query): array
    {
        $this->queries[] = $query;
        return array_shift($this->all) ?? [];
    }

    public function fetchValue(CompiledQuery $query): mixed
    {
        $this->queries[] = $query;
        return null;
    }
}
