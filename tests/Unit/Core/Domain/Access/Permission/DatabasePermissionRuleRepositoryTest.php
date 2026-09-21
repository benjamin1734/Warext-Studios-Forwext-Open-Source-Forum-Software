<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Domain\Access\Permission;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use Forwext\Core\Domain\Access\Permission\DatabasePermissionRuleRepository;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Access\Permission\PermissionEffect;
use Forwext\Core\Domain\Access\Permission\PermissionSubjectType;
use Forwext\Core\Domain\Access\UserAccessAssignment;
use Forwext\Core\Domain\Entity\EntityId;
use PHPUnit\Framework\TestCase;

final class DatabasePermissionRuleRepositoryTest extends TestCase
{
    public function testRuleLookupUsesBoundParametersForUserGroupsRolesAndNode(): void
    {
        $database = new PermissionRuleRecordingDatabase();
        $repository = new DatabasePermissionRuleRepository($database);
        $assignment = new UserAccessAssignment(
            EntityId::fromString('user:1'),
            EntityId::fromString('group:member'),
            [EntityId::fromString('group:verified')],
            [EntityId::fromString('role:moderator')],
        );

        $repository->rules(
            PermissionKey::fromString('forum.thread.create'),
            $assignment,
            EntityId::fromString('forum:10'),
        );

        self::assertCount(3, $database->fetchAllQueries);
        foreach (array_slice($database->fetchAllQueries, 0, 2) as $query) {
            self::assertStringNotContainsString('user:1', $query->sql);
            self::assertStringNotContainsString('group:member', $query->sql);
            self::assertSame('user:1', $query->parameters['user_id']);
            self::assertSame('group:member', $query->parameters['group_0']);
            self::assertSame('group:verified', $query->parameters['group_1']);
            self::assertSame('role:moderator', $query->parameters['role_0']);
        }
        self::assertSame('forum:10', $database->fetchAllQueries[1]->parameters['node_id']);
        self::assertStringContainsString('forwext_user_subscriptions', $database->fetchAllQueries[2]->sql);
        self::assertSame('user:1', $database->fetchAllQueries[2]->parameters['user_id']);
        self::assertSame('forum.thread.create', $database->fetchAllQueries[2]->parameters['permission_key']);
    }

    public function testActiveDisciplineRestrictionAddsUserDenyAtRequestedNodeScope(): void
    {
        $database = new PermissionRuleRecordingDatabase();
        $database->fetchValueResult = 1;
        $repository = new DatabasePermissionRuleRepository($database);
        $assignment = new UserAccessAssignment(
            EntityId::fromString('user:1'),
            EntityId::fromString('group:member'),
        );
        $nodeId = EntityId::fromString('forum:10');

        $rules = $repository->rules(
            PermissionKey::fromString('forum.thread.create'),
            $assignment,
            $nodeId,
        );

        self::assertCount(1, $rules);
        self::assertSame(PermissionSubjectType::User, $rules[0]->subjectType());
        self::assertSame(PermissionEffect::Deny, $rules[0]->effect());
        self::assertSame('user:1', $rules[0]->subjectId()->value());
        self::assertSame('forum:10', $rules[0]->nodeId()?->value());
        self::assertCount(1, $database->fetchValueQueries);
        self::assertSame('user:1', $database->fetchValueQueries[0]->parameters['user_id']);
        self::assertSame('posting', $database->fetchValueQueries[0]->parameters['restriction_0']);
        self::assertSame('content', $database->fetchValueQueries[0]->parameters['restriction_1']);
    }

    public function testNonContentPermissionDoesNotQueryDisciplineRestrictions(): void
    {
        $database = new PermissionRuleRecordingDatabase();
        $repository = new DatabasePermissionRuleRepository($database);
        $assignment = new UserAccessAssignment(
            EntityId::fromString('user:1'),
            EntityId::fromString('group:member'),
        );

        $repository->rules(
            PermissionKey::fromString('moderation.access'),
            $assignment,
            null,
        );

        self::assertSame([], $database->fetchValueQueries);
    }

    public function testDefinitionHydratesTypedPermissionMetadata(): void
    {
        $database = new PermissionRuleRecordingDatabase();
        $database->fetchOneResult = [
            'permission_key' => 'forum.thread.create',
            'value_type' => 'flag',
        ];

        $definition = (new DatabasePermissionRuleRepository($database))
            ->definition(PermissionKey::fromString('forum.thread.create'));

        self::assertNotNull($definition);
        self::assertSame('forum.thread.create', $definition->key()->value());
        self::assertSame('flag', $definition->valueType()->value);
    }
}

final class PermissionRuleRecordingDatabase implements QueryExecutor
{
    /** @var list<CompiledQuery> */
    public array $fetchAllQueries = [];

    /** @var array<string, mixed>|null */
    public ?array $fetchOneResult = null;

    /** @var list<CompiledQuery> */
    public array $fetchValueQueries = [];

    public mixed $fetchValueResult = null;

    public function execute(CompiledQuery $query): int
    {
        return 0;
    }

    public function fetchOne(CompiledQuery $query): ?array
    {
        return $this->fetchOneResult;
    }

    public function fetchAll(CompiledQuery $query): array
    {
        $this->fetchAllQueries[] = $query;
        return [];
    }

    public function fetchValue(CompiledQuery $query): mixed
    {
        $this->fetchValueQueries[] = $query;
        return $this->fetchValueResult;
    }
}
