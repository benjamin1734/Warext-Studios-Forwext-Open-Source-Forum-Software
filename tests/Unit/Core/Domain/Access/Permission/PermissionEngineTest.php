<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Domain\Access\Permission;

use Forwext\Core\Domain\Access\Permission\PermissionDecision;
use Forwext\Core\Domain\Access\Permission\PermissionDefinition;
use Forwext\Core\Domain\Access\Permission\PermissionEffect;
use Forwext\Core\Domain\Access\Permission\PermissionEngine;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Access\Permission\PermissionRule;
use Forwext\Core\Domain\Access\Permission\PermissionRuleRepository;
use Forwext\Core\Domain\Access\Permission\PermissionSubjectType;
use Forwext\Core\Domain\Access\Permission\PermissionValueType;
use Forwext\Core\Domain\Access\UserAccessAssignment;
use Forwext\Core\Domain\Entity\EntityId;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PermissionEngineTest extends TestCase
{
    public function testUnknownPermissionFailsClosed(): void
    {
        $decision = (new PermissionEngine(new PermissionEngineFakeRepository(null, [])))
            ->resolve($this->key(), $this->assignment());

        self::assertFalse($decision->isAllowed());
        self::assertSame('unknown_permission', $decision->reason());
    }

    public function testDenyWinsInsideSameMembershipTier(): void
    {
        $rules = [
            $this->rule(PermissionSubjectType::Group, 'group:member', PermissionEffect::Allow),
            $this->rule(PermissionSubjectType::Role, 'role:moderator', PermissionEffect::Deny),
        ];

        $decision = $this->resolveFlag($rules);

        self::assertFalse($decision->isAllowed());
        self::assertSame('global_membership_deny', $decision->reason());
    }

    public function testDirectUserOverrideOutranksMembershipRules(): void
    {
        $node = $this->id('forum:10');
        $rules = [
            $this->rule(PermissionSubjectType::User, 'user:1', PermissionEffect::Allow),
            $this->rule(PermissionSubjectType::Group, 'group:member', PermissionEffect::Deny, $node),
        ];

        $decision = $this->resolveFlag($rules, $node);

        self::assertTrue($decision->isAllowed());
        self::assertSame('global_user_allow', $decision->reason());
    }

    public function testNodeUserOverrideOutranksGlobalUserOverride(): void
    {
        $node = $this->id('forum:10');
        $rules = [
            $this->rule(PermissionSubjectType::User, 'user:1', PermissionEffect::Allow),
            $this->rule(PermissionSubjectType::User, 'user:1', PermissionEffect::Deny, $node),
        ];

        $decision = $this->resolveFlag($rules, $node);

        self::assertFalse($decision->isAllowed());
        self::assertSame('node_user_deny', $decision->reason());
    }

    public function testNodeMembershipOutranksGlobalMembershipAndInheritFallsThrough(): void
    {
        $node = $this->id('forum:10');
        $rules = [
            $this->rule(PermissionSubjectType::Group, 'group:member', PermissionEffect::Deny),
            $this->rule(PermissionSubjectType::Group, 'group:member', PermissionEffect::Inherit, $node),
            $this->rule(PermissionSubjectType::Role, 'role:moderator', PermissionEffect::Allow, $node),
        ];

        $decision = $this->resolveFlag($rules, $node);

        self::assertTrue($decision->isAllowed());
        self::assertSame('node_membership_allow', $decision->reason());
        self::assertCount(2, $decision->trace());
    }

    public function testNumericMembershipUsesMostRestrictiveAllowedLimit(): void
    {
        $rules = [
            $this->rule(PermissionSubjectType::Group, 'group:member', PermissionEffect::Allow, null, 50),
            $this->rule(PermissionSubjectType::Role, 'role:moderator', PermissionEffect::Allow, null, 20),
        ];
        $repository = new PermissionEngineFakeRepository(
            new PermissionDefinition($this->key(), PermissionValueType::Numeric),
            $rules,
        );

        $decision = (new PermissionEngine($repository))->resolve($this->key(), $this->assignment());

        self::assertTrue($decision->isAllowed());
        self::assertSame(20, $decision->numericLimit());
    }

    public function testMalformedNumericAllowFailsClosed(): void
    {
        $repository = new PermissionEngineFakeRepository(
            new PermissionDefinition($this->key(), PermissionValueType::Numeric),
            [$this->rule(PermissionSubjectType::Group, 'group:member', PermissionEffect::Allow)],
        );

        $decision = (new PermissionEngine($repository))->resolve($this->key(), $this->assignment());

        self::assertFalse($decision->isAllowed());
        self::assertSame('invalid_permission_rule', $decision->reason());
    }

    public function testRepositoryFailureFailsClosedWithoutLeakingDetails(): void
    {
        $repository = new class implements PermissionRuleRepository {
            public function definition(PermissionKey $key): ?PermissionDefinition
            {
                throw new RuntimeException('database password should never appear in a decision');
            }

            public function rules(PermissionKey $key, UserAccessAssignment $assignment, ?EntityId $nodeId): array
            {
                return [];
            }
        };

        $decision = (new PermissionEngine($repository))->resolve($this->key(), $this->assignment());

        self::assertFalse($decision->isAllowed());
        self::assertSame('permission_repository_error', $decision->reason());
        self::assertStringNotContainsString('password', $decision->reason());
    }

    /** @param list<PermissionRule> $rules */
    private function resolveFlag(array $rules, ?EntityId $nodeId = null): PermissionDecision
    {
        $repository = new PermissionEngineFakeRepository(
            new PermissionDefinition($this->key(), PermissionValueType::Flag),
            $rules,
        );

        return (new PermissionEngine($repository))->resolve($this->key(), $this->assignment(), $nodeId);
    }

    private function assignment(): UserAccessAssignment
    {
        return new UserAccessAssignment(
            $this->id('user:1'),
            $this->id('group:member'),
            [$this->id('group:verified')],
            [$this->id('role:moderator')],
        );
    }

    private function key(): PermissionKey
    {
        return PermissionKey::fromString('forum.thread.create');
    }

    private function rule(
        PermissionSubjectType $type,
        string $subjectId,
        PermissionEffect $effect,
        ?EntityId $nodeId = null,
        ?int $limit = null,
    ): PermissionRule {
        return new PermissionRule($type, $this->id($subjectId), $effect, $nodeId, $limit);
    }

    private function id(string $value): EntityId
    {
        return EntityId::fromString($value);
    }
}

final readonly class PermissionEngineFakeRepository implements PermissionRuleRepository
{
    /** @param list<PermissionRule> $rules */
    public function __construct(
        private ?PermissionDefinition $definition,
        private array $rules,
    ) {
    }

    public function definition(PermissionKey $key): ?PermissionDefinition
    {
        return $this->definition;
    }

    public function rules(PermissionKey $key, UserAccessAssignment $assignment, ?EntityId $nodeId): array
    {
        return $this->rules;
    }
}
