<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Domain\Access\Permission\Analyzer;

use Forwext\Core\Domain\Access\Permission\Analyzer\PermissionAnalysisLayerState;
use Forwext\Core\Domain\Access\Permission\Analyzer\PermissionAnalyzer;
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

final class PermissionAnalyzerTest extends TestCase
{
    public function testExplainsDirectNodeDenyAndStopsLowerLayers(): void
    {
        $node = $this->id('forum:10');
        $analysis = $this->analyze([
            $this->rule(PermissionSubjectType::User, 'user:1', PermissionEffect::Deny, $node),
            $this->rule(PermissionSubjectType::Group, 'group:member', PermissionEffect::Allow),
        ], $node);

        self::assertFalse($analysis->isAllowed());
        self::assertSame('node_user_deny', $analysis->reasonCode());
        self::assertSame(PermissionAnalysisLayerState::Denied, $analysis->layers()[0]->state());
        self::assertSame(PermissionAnalysisLayerState::NotReached, $analysis->layers()[1]->state());
        self::assertStringContainsString('direct user override', $analysis->summary());
        self::assertStringContainsString('deny wins', $analysis->layers()[0]->steps()[0]->explanation());
    }

    public function testVisualizesInheritanceBeforeMembershipAllow(): void
    {
        $node = $this->id('forum:10');
        $analysis = $this->analyze([
            $this->rule(PermissionSubjectType::User, 'user:1', PermissionEffect::Inherit, $node),
            $this->rule(PermissionSubjectType::Role, 'role:moderator', PermissionEffect::Allow, $node),
        ], $node);

        self::assertTrue($analysis->isAllowed());
        self::assertSame(PermissionAnalysisLayerState::Inherited, $analysis->layers()[0]->state());
        self::assertSame(PermissionAnalysisLayerState::NoRule, $analysis->layers()[1]->state());
        self::assertSame(PermissionAnalysisLayerState::Allowed, $analysis->layers()[2]->state());
        self::assertSame('inherited', $analysis->layers()[0]->steps()[0]->outcome());
    }

    public function testExplainsRestrictiveNumericLimit(): void
    {
        $repository = new PermissionAnalyzerFakeRepository(
            new PermissionDefinition($this->key(), PermissionValueType::Numeric),
            [
                $this->rule(PermissionSubjectType::Group, 'group:member', PermissionEffect::Allow, null, 50),
                $this->rule(PermissionSubjectType::Role, 'role:moderator', PermissionEffect::Allow, null, 20),
            ],
        );
        $analysis = (new PermissionAnalyzer(new PermissionEngine($repository)))
            ->analyze($this->key(), $this->assignment());

        self::assertTrue($analysis->isAllowed());
        self::assertSame(20, $analysis->numericLimit());
        self::assertStringContainsString('Effective numeric limit: 20.', $analysis->summary());
        self::assertCount(2, $analysis->layers()[3]->steps());
    }

    public function testShowsSecureFallbackForImplicitDeny(): void
    {
        $analysis = $this->analyze([]);

        self::assertFalse($analysis->isAllowed());
        self::assertSame('implicit_deny', $analysis->reasonCode());
        self::assertSame(PermissionAnalysisLayerState::Denied, $analysis->layers()[4]->state());
        self::assertStringContainsString('no applicable rule', strtolower($analysis->summary()));
    }

    public function testRepositoryFailureIsExplainedWithoutLeakingExceptionDetails(): void
    {
        $repository = new class implements PermissionRuleRepository {
            public function definition(PermissionKey $key): ?PermissionDefinition
            {
                throw new RuntimeException('secret database password');
            }

            public function rules(PermissionKey $key, UserAccessAssignment $assignment, ?EntityId $nodeId): array
            {
                return [];
            }
        };
        $analysis = (new PermissionAnalyzer(new PermissionEngine($repository)))
            ->analyze($this->key(), $this->assignment());

        self::assertFalse($analysis->isAllowed());
        self::assertSame(PermissionAnalysisLayerState::FailClosed, $analysis->layers()[4]->state());
        self::assertStringNotContainsString('password', $analysis->summary());
        self::assertStringContainsString('could not be read', $analysis->summary());
    }

    /** @param list<PermissionRule> $rules */
    private function analyze(array $rules, ?EntityId $nodeId = null): \Forwext\Core\Domain\Access\Permission\Analyzer\PermissionAnalysis
    {
        $repository = new PermissionAnalyzerFakeRepository(
            new PermissionDefinition($this->key(), PermissionValueType::Flag),
            $rules,
        );

        return (new PermissionAnalyzer(new PermissionEngine($repository)))
            ->analyze($this->key(), $this->assignment(), $nodeId);
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

final readonly class PermissionAnalyzerFakeRepository implements PermissionRuleRepository
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
