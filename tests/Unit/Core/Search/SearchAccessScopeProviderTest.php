<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Search;

use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDefinition;
use Forwext\Core\Domain\Access\Permission\PermissionEffect;
use Forwext\Core\Domain\Access\Permission\PermissionEngine;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Access\Permission\PermissionRule;
use Forwext\Core\Domain\Access\Permission\PermissionRuleRepository;
use Forwext\Core\Domain\Access\Permission\PermissionSubjectType;
use Forwext\Core\Domain\Access\Permission\PermissionValueType;
use Forwext\Core\Domain\Access\UserAccessAssignment;
use Forwext\Core\Domain\Access\UserAccessAssignmentProvider;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Node\ForumNode;
use Forwext\Core\Forum\Node\ForumNodeRepository;
use Forwext\Core\Forum\Node\ForumNodeSlug;
use Forwext\Core\Forum\Node\ForumNodeVisibility;
use Forwext\Core\Forum\Node\ForumSettings;
use Forwext\Core\Search\Access\ForumSearchAccessScopeProvider;
use PHPUnit\Framework\TestCase;

final class SearchAccessScopeProviderTest extends TestCase
{
    public function testUnlistedNodesAreNotSearchDiscoverableEvenWhenForumViewIsAllowed(): void
    {
        $listedId = EntityId::fromString('11111111111111111111111111111111');
        $unlistedId = EntityId::fromString('22222222222222222222222222222222');
        $nodes = [
            ForumNode::forum($listedId, null, 'Listed', ForumNodeSlug::fromString('listed'), new ForumSettings()),
            ForumNode::forum(
                $unlistedId,
                null,
                'Unlisted',
                ForumNodeSlug::fromString('unlisted'),
                new ForumSettings(),
                visibility: ForumNodeVisibility::Unlisted,
            ),
        ];
        $repository = new ScopeForumNodeRepository($nodes);
        $userId = EntityId::fromString('user-1');
        $groupId = EntityId::fromString('group-1');
        $assignment = new UserAccessAssignment($userId, $groupId);
        $rules = new ScopePermissionRepository($groupId);
        $authorizer = new PermissionAuthorizer(new PermissionEngine($rules), new ScopeAssignmentProvider($assignment));
        $provider = new ForumSearchAccessScopeProvider($repository, $authorizer);

        self::assertSame(
            ['forum.node:11111111111111111111111111111111'],
            $provider->scopes($userId),
        );
    }
}

final class ScopeForumNodeRepository implements ForumNodeRepository
{
    /** @param list<ForumNode> $nodes */
    public function __construct(private array $nodes)
    {
    }

    public function find(EntityId $nodeId): ?ForumNode
    {
        foreach ($this->nodes as $node) {
            if ($node->id()->equals($nodeId)) return $node;
        }
        return null;
    }

    public function findBySlug(ForumNodeSlug $slug): ?ForumNode
    {
        foreach ($this->nodes as $node) {
            if ($node->slug()->value() === $slug->value()) return $node;
        }
        return null;
    }

    public function all(): array
    {
        return $this->nodes;
    }

    public function save(ForumNode $node): void
    {
    }

    public function delete(EntityId $nodeId): void
    {
    }
}

final readonly class ScopeAssignmentProvider implements UserAccessAssignmentProvider
{
    public function __construct(private UserAccessAssignment $assignment)
    {
    }

    public function find(EntityId $userId): ?UserAccessAssignment
    {
        return $this->assignment->userId()->equals($userId) ? $this->assignment : null;
    }
}

final readonly class ScopePermissionRepository implements PermissionRuleRepository
{
    public function __construct(private EntityId $groupId)
    {
    }

    public function definition(PermissionKey $key): ?PermissionDefinition
    {
        return $key->value() === 'forum.view'
            ? new PermissionDefinition($key, PermissionValueType::Flag)
            : null;
    }

    public function rules(PermissionKey $key, UserAccessAssignment $assignment, ?EntityId $nodeId): array
    {
        if ($key->value() !== 'forum.view' || $nodeId === null) return [];
        return [new PermissionRule(PermissionSubjectType::Group, $this->groupId, PermissionEffect::Allow, $nodeId)];
    }
}
