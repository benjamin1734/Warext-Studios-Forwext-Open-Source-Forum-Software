<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Admin;

use Forwext\Core\Admin\Navigation\AdminNavigationRegistry;
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

final class SystemIntegrationNavigationTest extends TestCase
{
    public function testIntegrationNavigationRequiresDedicatedPermission(): void
    {
        $actor = EntityId::fromString('user:integration-navigation');

        $allowed = AdminNavigationRegistry::withCoreDefaults(
            $this->authorizer($actor, true),
        );
        self::assertNotNull($allowed->accessible($actor, 'admin.integrations'));

        $denied = AdminNavigationRegistry::withCoreDefaults(
            $this->authorizer($actor, false),
        );
        self::assertNull($denied->accessible($actor, 'admin.integrations'));
    }

    private function authorizer(EntityId $actor, bool $allow): PermissionAuthorizer
    {
        $key = PermissionKey::fromString('integration.manage');
        $definition = new PermissionDefinition($key, PermissionValueType::Flag);
        $rules = $allow
            ? [new PermissionRule(PermissionSubjectType::User, $actor, PermissionEffect::Allow)]
            : [];

        return new PermissionAuthorizer(
            new SystemIntegrationNavigationPermissionRepository($definition, $rules),
            new SystemIntegrationNavigationAssignmentProvider(new UserAccessAssignment(
                $actor,
                EntityId::fromString('group:member'),
            )),
        );
    }
}

/** @internal */
final readonly class SystemIntegrationNavigationPermissionRepository implements PermissionRuleRepository
{
    /** @param list<PermissionRule> $rules */
    public function __construct(
        private PermissionDefinition $definition,
        private array $rules,
    ) {
    }

    public function definition(PermissionKey $key): ?PermissionDefinition
    {
        return $key->value() === 'integration.manage' ? $this->definition : null;
    }

    public function rules(PermissionKey $key, UserAccessAssignment $assignment, ?EntityId $nodeId): array
    {
        return $key->value() === 'integration.manage' ? $this->rules : [];
    }
}

/** @internal */
final readonly class SystemIntegrationNavigationAssignmentProvider implements UserAccessAssignmentProvider
{
    public function __construct(private UserAccessAssignment $assignment)
    {
    }

    public function find(EntityId $userId): ?UserAccessAssignment
    {
        return $this->assignment->userId()->equals($userId) ? $this->assignment : null;
    }
}
