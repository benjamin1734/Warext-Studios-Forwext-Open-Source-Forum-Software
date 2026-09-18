<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Search;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use Forwext\Core\Faq\Search\DatabaseFaqSearchContentSource;
use Forwext\Core\Faq\Search\FaqSearchAccessScopeProvider;
use Forwext\Core\Search\Lifecycle\SearchIndexScope;
use Forwext\Core\Search\Lifecycle\Source\DatabasePostSearchContentSource;
use Forwext\Core\Search\Lifecycle\Source\DatabaseUserSearchContentSource;
use PHPUnit\Framework\TestCase;

final class SearchContentSourceTest extends TestCase
{
    public function testUserIndexContainsOnlyPublicIdentityDataAndAcceptsSecondPrecisionTimestamp(): void
    {
        $database = new SourceRecordingDatabase();
        $database->one = [[
            'user_id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'username' => 'benjamin17',
            'status' => 'active',
            'locale' => 'tr-TR',
            'updated_at_utc' => '2026-09-17 12:00:00',
        ]];
        $source = new DatabaseUserSearchContentSource($database);

        $document = $source->document('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');

        self::assertNotNull($document);
        self::assertSame('benjamin17', $document->title);
        self::assertSame('benjamin17', $document->body);
        self::assertSame([SearchIndexScope::PUBLIC], $document->accessScopes);
        self::assertSame('tr-TR', $document->locale);
        self::assertStringNotContainsString('email', strtolower($database->queries[0]->sql));
        self::assertStringNotContainsString('timezone', strtolower($database->queries[0]->sql));
    }

    public function testPostIndexRejectsPendingOrParentHiddenContent(): void
    {
        $database = new SourceRecordingDatabase();
        $database->one = [[
            'post_id' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            'body_source' => 'secret pending text',
            'moderation_state' => 'pending',
            'deleted' => 0,
            'updated_at_utc' => '2026-09-17 12:00:00.000001',
            'thread_title' => 'Pending thread',
            'forum_node_id' => 'cccccccccccccccccccccccccccccccc',
            'thread_state' => 'visible',
            'thread_deleted' => 0,
            'merged_into_thread_id' => null,
            'node_visibility' => 'listed',
        ]];
        $source = new DatabasePostSearchContentSource($database);

        self::assertNull($source->document('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'));
    }

    public function testFaqIndexUsesEffectiveVisibilityAndCarriesTags(): void
    {
        $database = new SourceRecordingDatabase();
        $database->one = [[
            'article_id' => 'dddddddddddddddddddddddddddddddd',
            'question' => 'How does FAQ work?',
            'answer' => 'Answer body',
            'article_visibility' => 'public',
            'category_visibility' => 'members',
            'language' => 'tr',
            'updated_at_utc' => '2026-09-18 18:00:00.000000',
            'article_active' => 1,
            'category_active' => 1,
        ]];
        $database->all = [[['tag_key'=>'support'],['tag_key'=>'help']]];
        $source = new DatabaseFaqSearchContentSource($database);

        $document = $source->document('dddddddddddddddddddddddddddddddd');

        self::assertNotNull($document);
        self::assertSame('faq.article', $document->documentType);
        self::assertSame([FaqSearchAccessScopeProvider::MEMBERS], $document->accessScopes);
        self::assertSame(['support','help'], $document->attributes['tag']);
        self::assertSame('tr', $document->locale);
    }

    public function testVisiblePostCarriesOnlyItsForumPermissionScope(): void
    {
        $database = new SourceRecordingDatabase();
        $database->one = [[
            'post_id' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            'body_source' => '[b]Visible[/b] message',
            'moderation_state' => 'visible',
            'deleted' => 0,
            'updated_at_utc' => '2026-09-17 12:00:00.000001',
            'thread_title' => 'Searchable thread',
            'forum_node_id' => 'cccccccccccccccccccccccccccccccc',
            'thread_state' => 'visible',
            'thread_deleted' => 0,
            'merged_into_thread_id' => null,
            'node_visibility' => 'listed',
        ]];
        $source = new DatabasePostSearchContentSource($database);

        $document = $source->document('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');

        self::assertNotNull($document);
        self::assertSame('Searchable thread', $document->title);
        self::assertSame('[b]Visible[/b] message', $document->body);
        self::assertSame(['forum.node:cccccccccccccccccccccccccccccccc'], $document->accessScopes);
    }
}

final class SourceRecordingDatabase implements QueryExecutor
{
    /** @var list<array<string, mixed>|null> */
    public array $one = [];
    /** @var list<list<array<string,mixed>>> */
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
