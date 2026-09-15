<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Domain\Access\Permission;

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
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PermissionAuthorizerTest extends TestCase
{
    public function testMissingAssignmentFailsClosed(): void
    {
        $authorizer = new PermissionAuthorizer(
            new PermissionEngine(new AuthorizerPermissionRepository()),
            new AuthorizerAssignmentProvider(null),
        );

        $decision = $authorizer->resolve(
            EntityId::fromString('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'),
            PermissionKey::fromString('support.ticket.create'),
        );

        self::assertFalse($decision->isAllowed());
        self::assertSame('missing_access_assignment', $decision->reason());
    }

    public function testAssignmentProviderFailureFailsClosedWithoutLeakingException(): void
    {
        $provider = new class implements UserAccessAssignmentProvider {
            public function find(EntityId $userId): ?UserAccessAssignment
            {
                throw new RuntimeException('secret database detail');
            }
        };
        $authorizer = new PermissionAuthorizer(
            new PermissionEngine(new AuthorizerPermissionRepository()),
            $provider,
        );

        $decision = $authorizer->resolve(
            EntityId::fromString('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'),
            PermissionKey::fromString('support.ticket.create'),
        );

        self::assertFalse($decision->isAllowed());
        self::assertSame('access_assignment_error', $decision->reason());
        self::assertStringNotContainsString('secret', $decision->reason());
    }

    public function testResolvedAssignmentUsesSharedPermissionEngine(): void
    {
        $assignment = new UserAccessAssignment(
            EntityId::fromString('cccccccccccccccccccccccccccccccc'),
            EntityId::fromString('group:member'),
        );
        $repository = new AuthorizerPermissionRepository();
        $repository->rules = [new PermissionRule(
            PermissionSubjectType::Group,
            EntityId::fromString('group:member'),
            PermissionEffect::Allow,
        )];
        $authorizer = new PermissionAuthorizer(
            new PermissionEngine($repository),
            new AuthorizerAssignmentProvider($assignment),
        );

        self::assertTrue($authorizer->allows(
            $assignment->userId(),
            PermissionKey::fromString('support.ticket.create'),
        ));
    }
}

final class AuthorizerAssignmentProvider implements UserAccessAssignmentProvider
{
    public function __construct(private readonly ?UserAccessAssignment $assignment)
    {
    }

    public function find(EntityId $userId): ?UserAccessAssignment
    {
        return $this->assignment;
    }
}

final class AuthorizerPermissionRepository implements PermissionRuleRepository
{
    /** @var list<PermissionRule> */
    public array $rules = [];

    public function definition(PermissionKey $key): ?PermissionDefinition
    {
        return new PermissionDefinition($key, PermissionValueType::Flag);
    }

    public function rules(PermissionKey $key, UserAccessAssignment $assignment, ?EntityId $nodeId): array
    {
        return $this->rules;
    }
}
