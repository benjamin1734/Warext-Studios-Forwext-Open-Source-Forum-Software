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

final class AdminNavigationRegistryTest extends TestCase
{
    public function testVisibleAndSearchResultsOnlyContainAuthorizedSurfaces(): void
    {
        $actor = EntityId::fromString('user:admin-navigation');
        $registry = AdminNavigationRegistry::withCoreDefaults(
            $this->authorizer($actor, ['appearance.manage']),
        );

        $visible = $registry->visible($actor);
        $keys = array_map(static fn ($item): string => $item->key, $visible);

        self::assertTrue(in_array('admin.appearance', $keys, true));
        self::assertTrue(in_array('admin.themes', $keys, true));
        self::assertTrue(in_array('admin.layout', $keys, true));
        self::assertFalse(in_array('admin.payments', $keys, true));

        $results = $registry->search($actor, 'tema');
        self::assertNotEmpty($results);
        foreach ($results as $result) {
            self::assertTrue(in_array($result->key, $keys, true));
        }
    }

    public function testAnyOfPermissionMakesSharedSurfaceVisible(): void
    {
        $actor = EntityId::fromString('user:admin-navigation');
        $registry = AdminNavigationRegistry::withCoreDefaults(
            $this->authorizer($actor, ['notice.manage']),
        );

        self::assertNotNull($registry->accessible($actor, 'admin.advertising'));
        self::assertNull($registry->accessible($actor, 'admin.payments'));
    }

    /** @param list<string> $allowed */
    private function authorizer(EntityId $actor, array $allowed): PermissionAuthorizer
    {
        $definitions = [];
        $rules = [];
        foreach ($allowed as $permission) {
            $key = PermissionKey::fromString($permission);
            $definitions[$permission] = new PermissionDefinition($key, PermissionValueType::Flag);
            $rules[$permission] = [
                new PermissionRule(PermissionSubjectType::User, $actor, PermissionEffect::Allow),
            ];
        }

        return new PermissionAuthorizer(
            new PermissionEngine(new AdminNavigationPermissionRepository($definitions, $rules)),
            new AdminNavigationAssignmentProvider(new UserAccessAssignment(
                $actor,
                EntityId::fromString('group:member'),
            )),
        );
    }
}

/** @internal */
final readonly class AdminNavigationPermissionRepository implements PermissionRuleRepository
{
    /**
     * @param array<string,PermissionDefinition> $definitions
     * @param array<string,list<PermissionRule>> $rules
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
        return $this->rules[$key->value()] ?? [];
    }
}

/** @internal */
final readonly class AdminNavigationAssignmentProvider implements UserAccessAssignmentProvider
{
    public function __construct(private UserAccessAssignment $assignment)
    {
    }

    public function find(EntityId $userId): ?UserAccessAssignment
    {
        return $this->assignment->userId()->equals($userId) ? $this->assignment : null;
    }
}
