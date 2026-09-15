<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Forum\Node;

use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDefinition;
use Forwext\Core\Domain\Access\Permission\PermissionEffect;
use Forwext\Core\Domain\Access\Permission\PermissionEngine;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Access\Permission\PermissionRule;
use Forwext\Core\Domain\Access\Permission\PermissionRuleRepository;
use Forwext\Core\Domain\Access\Permission\PermissionSubjectType;
use Forwext\Core\Domain\Access\Permission\PermissionValueType;
use Forwext\Core\Domain\Access\UserAccessAssignment;
use Forwext\Core\Domain\Access\UserAccessAssignmentProvider;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Node\ForumNode;
use Forwext\Core\Forum\Node\ForumNodeAuthorization;
use Forwext\Core\Forum\Node\ForumNodeHierarchy;
use Forwext\Core\Forum\Node\ForumNodeSlug;
use Forwext\Core\Forum\Node\ForumNodeVisibility;
use Forwext\Core\Forum\Node\ForumSettings;
use PHPUnit\Framework\TestCase;

final class ForumNodeAuthorizationTest extends TestCase
{
    public function testNodeScopedForumViewDoesNotLeakToSiblingNode(): void
    {
        $actor = $this->userId('1');
        $allowed = ForumNode::forum(
            $this->nodeId('a'),
            null,
            'Allowed',
            ForumNodeSlug::fromString('allowed'),
            new ForumSettings(),
        );
        $denied = ForumNode::forum(
            $this->nodeId('b'),
            null,
            'Denied',
            ForumNodeSlug::fromString('denied'),
            new ForumSettings(),
        );
        $hierarchy = new ForumNodeHierarchy([$allowed, $denied]);
        $repository = new ForumNodePermissionRepository($actor, $allowed->id());
        $authorization = new ForumNodeAuthorization(new PermissionGate(
            new PermissionAuthorizer(
                new PermissionEngine($repository),
                new ForumNodeAssignmentProvider($this->assignment($actor)),
            ),
            $actor,
        ));

        self::assertTrue($authorization->canView($hierarchy, $allowed->id()));
        self::assertFalse($authorization->canView($hierarchy, $denied->id()));
    }

    public function testDisabledAncestorFailsClosedBeforePermissionLookup(): void
    {
        $actor = $this->userId('1');
        $root = ForumNode::category(
            $this->nodeId('a'),
            null,
            'Root',
            ForumNodeSlug::fromString('root'),
            visibility: ForumNodeVisibility::Disabled,
        );
        $forum = ForumNode::forum(
            $this->nodeId('b'),
            $root->id(),
            'Forum',
            ForumNodeSlug::fromString('forum'),
            new ForumSettings(),
        );
        $repository = new ForumNodePermissionRepository($actor, $forum->id());
        $authorization = new ForumNodeAuthorization(new PermissionGate(
            new PermissionAuthorizer(
                new PermissionEngine($repository),
                new ForumNodeAssignmentProvider($this->assignment($actor)),
            ),
            $actor,
        ));

        $decision = $authorization->viewDecision(new ForumNodeHierarchy([$root, $forum]), $forum->id());

        self::assertFalse($decision->isAllowed());
        self::assertSame('forum_node_disabled', $decision->reason());
        self::assertSame(0, $repository->rulesCalls);
    }

    private function assignment(EntityId $actor): UserAccessAssignment
    {
        return new UserAccessAssignment($actor, EntityId::fromString('group:member'));
    }

    private function userId(string $seed): EntityId
    {
        return EntityId::fromString(str_repeat($seed, 32));
    }

    private function nodeId(string $seed): EntityId
    {
        return EntityId::fromString(str_repeat($seed, 32));
    }
}

final readonly class ForumNodeAssignmentProvider implements UserAccessAssignmentProvider
{
    public function __construct(private UserAccessAssignment $assignment)
    {
    }

    public function find(EntityId $userId): ?UserAccessAssignment
    {
        return $this->assignment->userId()->equals($userId) ? $this->assignment : null;
    }
}

final class ForumNodePermissionRepository implements PermissionRuleRepository
{
    public int $rulesCalls = 0;

    public function __construct(
        private readonly EntityId $actor,
        private readonly EntityId $allowedNode,
    ) {
    }

    public function definition(PermissionKey $key): ?PermissionDefinition
    {
        return $key->value() === 'forum.view'
            ? new PermissionDefinition($key, PermissionValueType::Flag)
            : null;
    }

    public function rules(PermissionKey $key, UserAccessAssignment $assignment, ?EntityId $nodeId): array
    {
        $this->rulesCalls++;
        if (!$assignment->userId()->equals($this->actor)
            || $nodeId === null
            || !$nodeId->equals($this->allowedNode)
        ) {
            return [];
        }

        return [new PermissionRule(
            PermissionSubjectType::User,
            $this->actor,
            PermissionEffect::Allow,
            $this->allowedNode,
        )];
    }
}
