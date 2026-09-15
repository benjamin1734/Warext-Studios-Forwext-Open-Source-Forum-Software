<?php

declare(strict_types=1);

namespace Forwext\Tests\Security\Permission;

use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDecision;
use Forwext\Core\Domain\Access\Permission\PermissionDefinition;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
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
use PHPUnit\Framework\TestCase;

final class PermissionSecurityMatrixTest extends TestCase
{
    private const ACTOR = '11111111111111111111111111111111';
    private const VICTIM = '22222222222222222222222222222222';

    public function testIdorVictimDirectGrantCannotAuthorizeDifferentBoundActor(): void
    {
        $key = $this->key('forum.thread.create');
        $gate = $this->gate(
            $this->assignment(self::ACTOR, ['role:member']),
            [$this->flag($key)],
            [$key->value() => [
                $this->rule(PermissionSubjectType::User, self::VICTIM, PermissionEffect::Allow),
            ]],
        );

        self::assertSame(self::ACTOR, $gate->actorId()->value());
        self::assertFalse($gate->allows($key));

        try {
            $gate->require($key);
            self::fail('Backend permission enforcement must reject another user\'s direct grant.');
        } catch (PermissionDeniedException $exception) {
            self::assertSame('Permission denied.', $exception->getMessage());
            self::assertSame('implicit_deny', $exception->decision()->reason());
        }
    }

    public function testBolaNodeGrantCannotBeReplayedAgainstSiblingNode(): void
    {
        $key = $this->key('forum.thread.create');
        $nodeA = $this->id('forum:alpha');
        $nodeB = $this->id('forum:beta');
        $gate = $this->gate(
            $this->assignment(self::ACTOR, ['role:member']),
            [$this->flag($key)],
            [$key->value() => [
                $this->rule(PermissionSubjectType::Group, 'group:member', PermissionEffect::Allow, $nodeA),
            ]],
        );

        self::assertTrue($gate->allows($key, $nodeA));
        self::assertFalse($gate->allows($key, $nodeB));
    }

    public function testModeratorCannotEscalateIntoAdministratorPermission(): void
    {
        $moderation = $this->key('moderation.manage');
        $admin = $this->key('acp.manage');
        $gate = $this->gate(
            $this->assignment(self::ACTOR, ['role:moderator']),
            [$this->flag($moderation), $this->flag($admin)],
            [
                $moderation->value() => [
                    $this->rule(PermissionSubjectType::Role, 'role:moderator', PermissionEffect::Allow),
                ],
                $admin->value() => [
                    $this->rule(PermissionSubjectType::Role, 'role:administrator', PermissionEffect::Allow),
                ],
            ],
        );

        self::assertTrue($gate->allows($moderation));
        self::assertFalse($gate->allows($admin));
        $this->assertBackendDenies($gate, $admin, 'implicit_deny');
    }

    public function testSameTierDenyBlocksRoleUnionPrivilegeBypass(): void
    {
        $key = $this->key('support.ticket.manage');
        $gate = $this->gate(
            $this->assignment(self::ACTOR, ['role:support', 'role:restricted']),
            [$this->flag($key)],
            [$key->value() => [
                $this->rule(PermissionSubjectType::Role, 'role:support', PermissionEffect::Allow),
                $this->rule(PermissionSubjectType::Role, 'role:restricted', PermissionEffect::Deny),
            ]],
        );

        $decision = $gate->decision($key);
        self::assertFalse($decision->isAllowed());
        self::assertSame('global_membership_deny', $decision->reason());
    }

    public function testNodeUserDenyOutranksGlobalUserAllow(): void
    {
        $key = $this->key('forum.post.create');
        $node = $this->id('forum:alpha');
        $gate = $this->gate(
            $this->assignment(self::ACTOR, ['role:member']),
            [$this->flag($key)],
            [$key->value() => [
                $this->rule(PermissionSubjectType::User, self::ACTOR, PermissionEffect::Allow),
                $this->rule(PermissionSubjectType::User, self::ACTOR, PermissionEffect::Deny, $node),
            ]],
        );

        $decision = $gate->decision($key, $node);
        self::assertFalse($decision->isAllowed());
        self::assertSame('node_user_deny', $decision->reason());
    }

    public function testNodeUserInheritFallsThroughToGlobalUserBeforeMembership(): void
    {
        $key = $this->key('forum.post.create');
        $node = $this->id('forum:alpha');
        $gate = $this->gate(
            $this->assignment(self::ACTOR, ['role:member']),
            [$this->flag($key)],
            [$key->value() => [
                $this->rule(PermissionSubjectType::User, self::ACTOR, PermissionEffect::Inherit, $node),
                $this->rule(PermissionSubjectType::User, self::ACTOR, PermissionEffect::Allow),
                $this->rule(PermissionSubjectType::Group, 'group:member', PermissionEffect::Deny, $node),
            ]],
        );

        $decision = $gate->decision($key, $node);
        self::assertTrue($decision->isAllowed());
        self::assertSame('global_user_allow', $decision->reason());
    }

    public function testNodeMembershipInheritFallsThroughToGlobalMembershipDeny(): void
    {
        $key = $this->key('marketplace.listing.create');
        $node = $this->id('forum:market');
        $gate = $this->gate(
            $this->assignment(self::ACTOR, ['role:member']),
            [$this->flag($key)],
            [$key->value() => [
                $this->rule(PermissionSubjectType::Group, 'group:member', PermissionEffect::Inherit, $node),
                $this->rule(PermissionSubjectType::Group, 'group:member', PermissionEffect::Deny),
            ]],
        );

        $decision = $gate->decision($key, $node);
        self::assertFalse($decision->isAllowed());
        self::assertSame('global_membership_deny', $decision->reason());
    }

    public function testUiAndBackendUseSameDecisionForAllowedPermission(): void
    {
        $key = $this->key('portfolio.create');
        $gate = $this->gate(
            $this->assignment(self::ACTOR, ['role:member']),
            [$this->flag($key)],
            [$key->value() => [
                $this->rule(PermissionSubjectType::Group, 'group:member', PermissionEffect::Allow),
            ]],
        );

        $uiVisible = $gate->allows($key);
        $backendDecision = $gate->require($key);

        self::assertTrue($uiVisible);
        self::assertTrue($backendDecision->isAllowed());
        self::assertSame($gate->decision($key)->reason(), $backendDecision->reason());
    }

    public function testUiAndBackendUseSameDecisionForDeniedPermission(): void
    {
        $key = $this->key('analytics.view_site');
        $gate = $this->gate(
            $this->assignment(self::ACTOR, ['role:member']),
            [$this->flag($key)],
            [],
        );

        $uiVisible = $gate->allows($key);
        self::assertFalse($uiVisible);
        $this->assertBackendDenies($gate, $key, $gate->decision($key)->reason());
    }

    public function testNumericInheritanceKeepsMostRestrictiveSameTierLimit(): void
    {
        $key = $this->key('forum.content.daily_limit');
        $gate = $this->gate(
            $this->assignment(self::ACTOR, ['role:member', 'role:verified']),
            [new PermissionDefinition($key, PermissionValueType::Numeric)],
            [$key->value() => [
                $this->rule(PermissionSubjectType::Role, 'role:member', PermissionEffect::Allow, null, 100),
                $this->rule(PermissionSubjectType::Role, 'role:verified', PermissionEffect::Allow, null, 250),
            ]],
        );

        $decision = $gate->require($key);
        self::assertSame(100, $decision->numericLimit());
    }

    private function assertBackendDenies(PermissionGate $gate, PermissionKey $key, string $reason): void
    {
        try {
            $gate->require($key);
            self::fail('Backend permission gate must deny when UI visibility denies.');
        } catch (PermissionDeniedException $exception) {
            self::assertSame('Permission denied.', $exception->getMessage());
            self::assertSame($reason, $exception->decision()->reason());
        }
    }

    /**
     * @param list<PermissionDefinition> $definitions
     * @param array<string, list<PermissionRule>> $rules
     */
    private function gate(
        UserAccessAssignment $assignment,
        array $definitions,
        array $rules,
    ): PermissionGate {
        $definitionMap = [];
        foreach ($definitions as $definition) {
            $definitionMap[$definition->key()->value()] = $definition;
        }

        $provider = new PermissionSecurityMatrixAssignmentProvider([
            $assignment->userId()->value() => $assignment,
        ]);
        $repository = new PermissionSecurityMatrixRuleRepository($definitionMap, $rules);
        $authorizer = new PermissionAuthorizer(new PermissionEngine($repository), $provider);

        return new PermissionGate($authorizer, $assignment->userId());
    }

    /** @param list<string> $roles */
    private function assignment(string $userId, array $roles): UserAccessAssignment
    {
        return new UserAccessAssignment(
            $this->id($userId),
            $this->id('group:member'),
            [$this->id('group:verified')],
            array_map($this->id(...), $roles),
        );
    }

    private function flag(PermissionKey $key): PermissionDefinition
    {
        return new PermissionDefinition($key, PermissionValueType::Flag);
    }

    private function key(string $value): PermissionKey
    {
        return PermissionKey::fromString($value);
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

final readonly class PermissionSecurityMatrixAssignmentProvider implements UserAccessAssignmentProvider
{
    /** @param array<string, UserAccessAssignment> $assignments */
    public function __construct(private array $assignments)
    {
    }

    public function find(EntityId $userId): ?UserAccessAssignment
    {
        return $this->assignments[$userId->value()] ?? null;
    }
}

final readonly class PermissionSecurityMatrixRuleRepository implements PermissionRuleRepository
{
    /**
     * @param array<string, PermissionDefinition> $definitions
     * @param array<string, list<PermissionRule>> $rules
     */
    public function __construct(
        private array $definitions,
        private array $rules,
    ) {
    }

    public function definition(PermissionKey $key): ?PermissionDefinition
    {
        return $this->definitions[$key->value()] ?? null;
    }

    public function rules(PermissionKey $key, UserAccessAssignment $assignment, ?EntityId $nodeId): array
    {
        $groups = [
            $assignment->primaryGroupId()->value(),
            ...array_map(static fn (EntityId $id): string => $id->value(), $assignment->secondaryGroupIds()),
        ];
        $roles = array_map(static fn (EntityId $id): string => $id->value(), $assignment->roleIds());

        return array_values(array_filter(
            $this->rules[$key->value()] ?? [],
            static function (PermissionRule $rule) use ($assignment, $groups, $roles, $nodeId): bool {
                $subjectMatches = match ($rule->subjectType()) {
                    PermissionSubjectType::User => $rule->subjectId()->equals($assignment->userId()),
                    PermissionSubjectType::Group => in_array($rule->subjectId()->value(), $groups, true),
                    PermissionSubjectType::Role => in_array($rule->subjectId()->value(), $roles, true),
                };
                if (!$subjectMatches) {
                    return false;
                }

                $ruleNode = $rule->nodeId();
                if ($ruleNode === null) {
                    return true;
                }

                return $nodeId !== null && $ruleNode->equals($nodeId);
            },
        ));
    }
}
