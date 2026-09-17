<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Search;

use Closure;
use DateTimeImmutable;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDefinition;
use Forwext\Core\Domain\Access\Permission\PermissionEffect;
use Forwext\Core\Domain\Access\Permission\PermissionEngine;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Access\Permission\PermissionRule;
use Forwext\Core\Domain\Access\Permission\PermissionRuleRepository;
use Forwext\Core\Domain\Access\Permission\PermissionSubjectType;
use Forwext\Core\Domain\Access\Permission\PermissionValueType;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\UserAccessAssignment;
use Forwext\Core\Domain\Access\UserAccessAssignmentProvider;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Search\Access\SearchAccessScopeProvider;
use Forwext\Core\Search\Discovery\DatabaseThreadDiscoveryRepository;
use Forwext\Core\Search\Discovery\DiscoveryMode;
use Forwext\Core\Search\Discovery\ThreadDiscoveryRepository;
use Forwext\Core\Search\Discovery\ThreadDiscoveryService;
use PHPUnit\Framework\TestCase;

final class ThreadDiscoveryTest extends TestCase
{
    public function testServicePassesOnlyForumScopesToRepositoryAndDeduplicatesThem(): void
    {
        $userId = EntityId::fromString('99999999999999999999999999999999');
        $groupId = EntityId::fromString('group-1');
        $repository = new RecordingDiscoveryRepository();
        $service = new ThreadDiscoveryService(
            $repository,
            self::authorizer($userId, $groupId, true),
            [new StaticDiscoveryScopeProvider([
                'public',
                'forum.node:22222222222222222222222222222222',
                'forum.node:11111111111111111111111111111111',
                'forum.node:22222222222222222222222222222222',
            ])],
        );

        $service->discover(
            $userId,
            DiscoveryMode::RecentActivity,
            now: new DateTimeImmutable('2026-09-17T20:00:00+00:00'),
        );

        self::assertSame([
            '11111111111111111111111111111111',
            '22222222222222222222222222222222',
        ], $repository->forumNodeIds);
    }

    public function testServiceRejectsDiscoveryWithoutSearchPermission(): void
    {
        $userId = EntityId::fromString('99999999999999999999999999999999');
        $service = new ThreadDiscoveryService(
            new RecordingDiscoveryRepository(),
            self::authorizer($userId, EntityId::fromString('group-1'), false),
            [new StaticDiscoveryScopeProvider(['forum.node:11111111111111111111111111111111'])],
        );

        $this->expectException(PermissionDeniedException::class);
        $service->discover($userId, DiscoveryMode::New);
    }

    public function testDatabaseRepositoryBuildsUnreadQueryWithBoundForumScopes(): void
    {
        $executor = new RecordingDiscoveryExecutor([[
            'thread_id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'forum_node_id' => '11111111111111111111111111111111',
            'author_user_id' => null,
            'title' => 'Unread topic',
            'featured' => 0,
            'created_at_utc' => '2026-09-17 10:00:00.000000',
            'activity_at_utc' => '2026-09-17 12:00:00.000000',
            'visible_post_count' => 3,
            'recent_post_count' => 3,
            'unread' => 1,
        ]]);
        $repository = new DatabaseThreadDiscoveryRepository($executor);

        $threads = $repository->discover(
            EntityId::fromString('99999999999999999999999999999999'),
            ['11111111111111111111111111111111'],
            DiscoveryMode::Unread,
            new DateTimeImmutable('2026-09-17T20:00:00+00:00'),
            20,
            0,
        );

        self::assertCount(1, $threads);
        self::assertTrue($threads[0]->unread);
        self::assertSame(2, $threads[0]->replyCount());
        self::assertNotNull($executor->query);
        self::assertStringContainsString('HAVING `unread` = 1', $executor->query->sql);
        self::assertStringContainsString('`t`.`forum_node_id` IN (:forum_0)', $executor->query->sql);
        self::assertSame(
            '11111111111111111111111111111111',
            $executor->query->parameters['forum_0'] ?? null,
        );
        self::assertSame('2026-09-10 20:00:00.000000', $executor->query->parameters['trend_since'] ?? null);
    }

    public function testTrendingUsesBoundedSevenDayPostActivityAndStableOrdering(): void
    {
        $executor = new RecordingDiscoveryExecutor([]);
        $repository = new DatabaseThreadDiscoveryRepository($executor);

        $repository->discover(
            EntityId::fromString('99999999999999999999999999999999'),
            ['11111111111111111111111111111111'],
            DiscoveryMode::Trending,
            new DateTimeImmutable('2026-09-17T20:00:00+00:00'),
            10,
            5,
        );

        self::assertNotNull($executor->query);
        self::assertStringContainsString(
            'HAVING `recent_post_count` > 0',
            $executor->query->sql,
        );
        self::assertStringContainsString(
            'ORDER BY `recent_post_count` DESC, `activity_at_utc` DESC',
            $executor->query->sql,
        );
        self::assertStringContainsString('`t`.`thread_id` DESC LIMIT 10 OFFSET 5', $executor->query->sql);
    }

    private static function authorizer(EntityId $userId, EntityId $groupId, bool $allow): PermissionAuthorizer
    {
        return new PermissionAuthorizer(
            new PermissionEngine(new DiscoveryPermissionRepository($groupId, $allow)),
            new DiscoveryAssignmentProvider(new UserAccessAssignment($userId, $groupId)),
        );
    }
}

final class RecordingDiscoveryRepository implements ThreadDiscoveryRepository
{
    /** @var list<string> */
    public array $forumNodeIds = [];

    public function discover(
        EntityId $userId,
        array $forumNodeIds,
        DiscoveryMode $mode,
        DateTimeImmutable $now,
        int $limit,
        int $offset,
    ): array {
        $this->forumNodeIds = $forumNodeIds;
        return [];
    }
}

final readonly class StaticDiscoveryScopeProvider implements SearchAccessScopeProvider
{
    /** @param list<string> $scopes */
    public function __construct(private array $scopes)
    {
    }

    public function scopes(EntityId $userId): array
    {
        return $this->scopes;
    }
}

final readonly class DiscoveryAssignmentProvider implements UserAccessAssignmentProvider
{
    public function __construct(private UserAccessAssignment $assignment)
    {
    }

    public function find(EntityId $userId): ?UserAccessAssignment
    {
        return $this->assignment->userId()->equals($userId) ? $this->assignment : null;
    }
}

final readonly class DiscoveryPermissionRepository implements PermissionRuleRepository
{
    public function __construct(
        private EntityId $groupId,
        private bool $allow,
    ) {
    }

    public function definition(PermissionKey $key): ?PermissionDefinition
    {
        return $key->value() === 'search.use'
            ? new PermissionDefinition($key, PermissionValueType::Flag)
            : null;
    }

    public function rules(PermissionKey $key, UserAccessAssignment $assignment, ?EntityId $nodeId): array
    {
        if ($key->value() !== 'search.use' || $nodeId !== null || !$this->allow) {
            return [];
        }

        return [
            new PermissionRule(
                PermissionSubjectType::Group,
                $this->groupId,
                PermissionEffect::Allow,
            ),
        ];
    }
}

final class RecordingDiscoveryExecutor implements TransactionalQueryExecutor
{
    public ?CompiledQuery $query = null;

    /** @param list<array<string, mixed>> $rows */
    public function __construct(private array $rows)
    {
    }

    public function execute(CompiledQuery $query): int
    {
        return 0;
    }

    public function fetchOne(CompiledQuery $query): ?array
    {
        return null;
    }

    public function fetchAll(CompiledQuery $query): array
    {
        $this->query = $query;
        return $this->rows;
    }

    public function fetchValue(CompiledQuery $query): mixed
    {
        return null;
    }

    public function inTransaction(): bool
    {
        return false;
    }

    public function transaction(Closure $callback): mixed
    {
        return $callback($this);
    }
}
