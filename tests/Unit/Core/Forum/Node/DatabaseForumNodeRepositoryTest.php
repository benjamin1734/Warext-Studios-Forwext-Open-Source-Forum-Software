<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Forum\Node;

use Closure;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Node\DatabaseForumNodeRepository;
use Forwext\Core\Forum\Node\ForumDefaultThreadSort;
use Forwext\Core\Forum\Node\ForumNode;
use Forwext\Core\Forum\Node\ForumNodeSlug;
use Forwext\Core\Forum\Node\ForumNodeVisibility;
use Forwext\Core\Forum\Node\ForumSettings;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DatabaseForumNodeRepositoryTest extends TestCase
{
    public function testForumSaveLocksHierarchyAndUsesBoundParameters(): void
    {
        $database = new ForumNodeRecordingDatabase();
        $repository = new DatabaseForumNodeRepository($database);
        $node = ForumNode::forum(
            $this->id('1'),
            null,
            'General',
            ForumNodeSlug::fromString('general'),
            new ForumSettings(false, true, true, false, ForumDefaultThreadSort::Created, 40),
            'General discussion.',
            15,
        );

        $repository->save($node);

        self::assertTrue($database->transactionUsed);
        self::assertCount(1, $database->fetchAllQueries);
        self::assertTrue($database->fetchAllQueries[0]->requiresTransaction);
        self::assertStringContainsString('FOR UPDATE', $database->fetchAllQueries[0]->sql);
        self::assertCount(2, $database->executedQueries);
        $nodeQuery = $database->executedQueries[0];
        self::assertStringNotContainsString($node->id()->value(), $nodeQuery->sql);
        self::assertSame($node->id()->value(), $nodeQuery->parameters['node_id']);
        self::assertSame('general', $nodeQuery->parameters['slug']);
        self::assertSame('forum', $nodeQuery->parameters['node_type']);
        $settingsQuery = $database->executedQueries[1];
        self::assertSame(40, $settingsQuery->parameters['threads_per_page']);
        self::assertSame('created', $settingsQuery->parameters['default_thread_sort']);
        self::assertTrue($settingsQuery->parameters['require_thread_approval']);
    }

    public function testChangingForumToCategoryRemovesForumSettings(): void
    {
        $database = new ForumNodeRecordingDatabase();
        $database->fetchAllResults = [[
            $this->forumRow('1', 'general'),
        ]];
        $repository = new DatabaseForumNodeRepository($database);

        $repository->save(ForumNode::category(
            $this->id('1'),
            null,
            'General container',
            ForumNodeSlug::fromString('general'),
        ));

        self::assertCount(2, $database->executedQueries);
        self::assertStringStartsWith('DELETE FROM `forwext_forum_settings`', $database->executedQueries[1]->sql);
        self::assertSame($this->id('1')->value(), $database->executedQueries[1]->parameters['node_id']);
    }

    public function testFindHydratesForumSettingsAndVisibility(): void
    {
        $database = new ForumNodeRecordingDatabase();
        $database->fetchOneResult = $this->forumRow('1', 'general', 'unlisted');

        $node = (new DatabaseForumNodeRepository($database))->find($this->id('1'));

        self::assertNotNull($node);
        self::assertSame(ForumNodeVisibility::Unlisted, $node->visibility());
        self::assertSame(40, $node->forumSettings()?->threadsPerPage());
        self::assertSame(ForumDefaultThreadSort::Created, $node->forumSettings()?->defaultThreadSort());
    }

    public function testDeleteFailsBeforeDatabaseDeleteWhenChildrenExist(): void
    {
        $database = new ForumNodeRecordingDatabase();
        $database->fetchValueResult = 1;
        $repository = new DatabaseForumNodeRepository($database);

        try {
            $repository->delete($this->id('1'));
            self::fail('Node with children must not be deleted.');
        } catch (InvalidArgumentException) {
            self::assertSame([], $database->executedQueries);
        }
    }

    /** @return array<string, mixed> */
    private function forumRow(string $seed, string $slug, string $visibility = 'listed'): array
    {
        return [
            'node_id' => str_repeat($seed, 32),
            'parent_id' => null,
            'node_type' => 'forum',
            'title' => 'General',
            'slug' => $slug,
            'description' => 'General discussion.',
            'visibility' => $visibility,
            'sort_order' => 15,
            'page_content' => null,
            'link_target' => null,
            'link_new_window' => 0,
            'allow_new_threads' => 0,
            'allow_replies' => 1,
            'require_thread_approval' => 1,
            'require_post_approval' => 0,
            'default_thread_sort' => 'created',
            'threads_per_page' => 40,
        ];
    }

    private function id(string $seed): EntityId
    {
        return EntityId::fromString(str_repeat($seed, 32));
    }
}

final class ForumNodeRecordingDatabase implements TransactionalQueryExecutor
{
    /** @var list<CompiledQuery> */
    public array $executedQueries = [];

    /** @var list<CompiledQuery> */
    public array $fetchAllQueries = [];

    /** @var list<list<array<string, mixed>>> */
    public array $fetchAllResults = [];

    /** @var array<string, mixed>|null */
    public ?array $fetchOneResult = null;

    public mixed $fetchValueResult = 0;
    public bool $transactionUsed = false;
    private bool $inTransaction = false;

    public function execute(CompiledQuery $query): int
    {
        $this->executedQueries[] = $query;
        return 1;
    }

    public function fetchOne(CompiledQuery $query): ?array
    {
        return $this->fetchOneResult;
    }

    public function fetchAll(CompiledQuery $query): array
    {
        $this->fetchAllQueries[] = $query;
        return array_shift($this->fetchAllResults) ?? [];
    }

    public function fetchValue(CompiledQuery $query): mixed
    {
        return $this->fetchValueResult;
    }

    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    public function transaction(Closure $callback): mixed
    {
        $this->transactionUsed = true;
        $this->inTransaction = true;
        try {
            return $callback($this);
        } finally {
            $this->inTransaction = false;
        }
    }
}
