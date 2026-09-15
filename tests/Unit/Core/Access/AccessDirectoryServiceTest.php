<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Access;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Access\AccessDirectoryService;
use Forwext\Core\Access\AccessDirectoryStore;
use Forwext\Core\Access\AccessException;
use Forwext\Core\Access\CanonicalUserGroupProvider;
use Forwext\Core\Access\GroupDefinition;
use Forwext\Core\Access\GroupKey;
use Forwext\Core\Access\RoleAssignment;
use Forwext\Core\Access\RoleAssignmentSource;
use Forwext\Core\Access\RoleDefinition;
use Forwext\Core\Access\RoleKey;
use Forwext\Core\Access\RoleKind;
use Forwext\Core\Access\UserAccessMembership;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use PHPUnit\Framework\TestCase;

final class AccessDirectoryServiceTest extends TestCase
{
    public function testGroupAndRoleKeysAreCanonicalButRemainDistinctTypes(): void
    {
        $group = GroupKey::fromString('  Verified-Member  ');
        $role = RoleKey::fromString('  Verified-Member  ');

        self::assertSame('verified-member', $group->value());
        self::assertSame('verified-member', $role->value());
        self::assertNotSame($group::class, $role::class);
    }

    public function testUserHasExactlyOnePrimaryGroupAndDistinctSecondaryGroups(): void
    {
        $user = UserId::generate();
        $actor = UserId::generate();
        $store = new MemoryAccessDirectoryStore();
        $store->seedGroup(new GroupDefinition(GroupKey::fromString('registered'), 'Registered', system: true));
        $store->seedGroup(new GroupDefinition(GroupKey::fromString('verified'), 'Verified'));
        $store->seedGroup(new GroupDefinition(GroupKey::fromString('supporters'), 'Supporters'));
        $store->seedUser($user, GroupKey::fromString('registered'));
        $service = new AccessDirectoryService($store);

        self::assertTrue($service->addSecondaryGroup(
            $user,
            GroupKey::fromString('verified'),
            $actor,
            'admin.verify',
            $this->now(),
        ));
        self::assertTrue($service->addSecondaryGroup(
            $user,
            GroupKey::fromString('supporters'),
            $actor,
            'admin.supporter',
            $this->now(),
        ));

        $membership = $service->membershipFor($user);
        self::assertSame('registered', $membership->primaryGroup->value());
        self::assertSame(['supporters', 'verified'], array_map(
            static fn (GroupKey $group): string => $group->value(),
            $membership->secondaryGroups,
        ));

        self::assertTrue($service->setPrimaryGroup(
            $user,
            GroupKey::fromString('verified'),
            $actor,
            'admin.primary',
            $this->now(),
        ));
        $membership = $service->membershipFor($user);
        self::assertSame('verified', $membership->primaryGroup->value());
        self::assertFalse($membership->hasGroup(GroupKey::fromString('registered')));
        self::assertTrue($membership->hasGroup(GroupKey::fromString('supporters')));
    }

    public function testPrimaryGroupCannotBeAddedAsSecondary(): void
    {
        $user = UserId::generate();
        $actor = UserId::generate();
        $store = new MemoryAccessDirectoryStore();
        $store->seedGroup(new GroupDefinition(GroupKey::fromString('registered'), 'Registered', system: true));
        $store->seedUser($user, GroupKey::fromString('registered'));
        $service = new AccessDirectoryService($store);

        $this->expectException(AccessException::class);
        $service->addSecondaryGroup(
            $user,
            GroupKey::fromString('registered'),
            $actor,
            'admin.group',
            $this->now(),
        );
    }

    public function testManagedAndSystemDefinitionsHaveSeparateManagementPaths(): void
    {
        $actor = UserId::generate();
        $store = new MemoryAccessDirectoryStore();
        $service = new AccessDirectoryService($store);

        $service->saveGroup(
            new GroupDefinition(GroupKey::fromString('members'), 'Members'),
            $actor,
            'admin.create',
            $this->now(),
        );
        $service->saveRole(
            new RoleDefinition(RoleKey::fromString('moderator'), 'Moderator', RoleKind::Staff),
            $actor,
            'admin.create',
            $this->now(),
        );
        $service->saveRole(
            new RoleDefinition(RoleKey::fromString('system-sync'), 'System Sync', RoleKind::System),
            null,
            'system.bootstrap',
            $this->now(),
        );

        self::assertFalse($store->findGroup(GroupKey::fromString('members'))?->system);
        self::assertSame(RoleKind::Staff, $store->findRole(RoleKey::fromString('moderator'))?->kind);
        self::assertSame(RoleKind::System, $store->findRole(RoleKey::fromString('system-sync'))?->kind);
    }

    public function testUserActionCannotCreateOrReclassifySystemObjects(): void
    {
        $actor = UserId::generate();
        $store = new MemoryAccessDirectoryStore();
        $store->seedRole(new RoleDefinition(RoleKey::fromString('helper'), 'Helper', RoleKind::Standard));
        $service = new AccessDirectoryService($store);

        try {
            $service->saveGroup(
                new GroupDefinition(GroupKey::fromString('system-users'), 'System Users', system: true),
                $actor,
                'admin.create',
                $this->now(),
            );
            self::fail('A user action must not create a system group.');
        } catch (AccessException) {
            self::assertNull($store->findGroup(GroupKey::fromString('system-users')));
        }

        $this->expectException(AccessException::class);
        $service->saveRole(
            new RoleDefinition(RoleKey::fromString('helper'), 'Helper', RoleKind::Staff),
            $actor,
            'admin.escalate',
            $this->now(),
        );
    }

    public function testStaffAndSystemRolesCannotBeGrantedByUpgradeOrPromotion(): void
    {
        $user = UserId::generate();
        $actor = UserId::generate();
        $store = new MemoryAccessDirectoryStore();
        $store->seedGroup(new GroupDefinition(GroupKey::fromString('registered'), 'Registered', system: true));
        $store->seedRole(new RoleDefinition(RoleKey::fromString('moderator'), 'Moderator', RoleKind::Staff));
        $store->seedRole(new RoleDefinition(RoleKey::fromString('system-sync'), 'System Sync', RoleKind::System));
        $store->seedRole(new RoleDefinition(RoleKey::fromString('supporter'), 'Supporter', RoleKind::Standard));
        $store->seedUser($user, GroupKey::fromString('registered'));
        $service = new AccessDirectoryService($store);

        try {
            $service->assignRole(
                $user,
                RoleKey::fromString('moderator'),
                RoleAssignmentSource::Upgrade,
                null,
                'upgrade.apply',
                $this->now(),
            );
            self::fail('An upgrade must not grant a staff role.');
        } catch (AccessException) {
            self::assertFalse($service->membershipFor($user)->hasRole(RoleKey::fromString('moderator')));
        }

        try {
            $service->assignRole(
                $user,
                RoleKey::fromString('system-sync'),
                RoleAssignmentSource::Manual,
                $actor,
                'admin.assign',
                $this->now(),
            );
            self::fail('A manual action must not grant a system role.');
        } catch (AccessException) {
            self::assertFalse($service->membershipFor($user)->hasRole(RoleKey::fromString('system-sync')));
        }

        self::assertTrue($service->assignRole(
            $user,
            RoleKey::fromString('supporter'),
            RoleAssignmentSource::Upgrade,
            null,
            'upgrade.apply',
            $this->now(),
        ));
        self::assertTrue($service->assignRole(
            $user,
            RoleKey::fromString('moderator'),
            RoleAssignmentSource::Manual,
            $actor,
            'admin.assign',
            $this->now(),
        ));
        self::assertTrue($service->assignRole(
            $user,
            RoleKey::fromString('system-sync'),
            RoleAssignmentSource::System,
            null,
            'system.assign',
            $this->now(),
        ));
    }

    public function testCanonicalMfaGroupProviderUsesPrimaryAndSecondaryGroupsButNotRoles(): void
    {
        $user = UserId::generate();
        $store = new MemoryAccessDirectoryStore();
        $store->seedGroup(new GroupDefinition(GroupKey::fromString('registered'), 'Registered', system: true));
        $store->seedGroup(new GroupDefinition(GroupKey::fromString('verified'), 'Verified'));
        $store->seedRole(new RoleDefinition(RoleKey::fromString('moderator'), 'Moderator', RoleKind::Staff));
        $store->seedUser($user, GroupKey::fromString('registered'));
        $store->addSecondaryGroup($user, GroupKey::fromString('verified'), null, null, $this->now());
        $store->assignRole(
            $user,
            RoleKey::fromString('moderator'),
            RoleAssignmentSource::System,
            null,
            null,
            $this->now(),
        );

        $groups = (new CanonicalUserGroupProvider($store))->groupsFor($user);
        self::assertSame(['registered', 'verified'], $groups);
        self::assertNotContains('moderator', $groups);
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-15 17:20:00', new DateTimeZone('UTC'));
    }
}

final class MemoryAccessDirectoryStore implements AccessDirectoryStore
{
    /** @var array<string, GroupDefinition> */
    private array $groups = [];

    /** @var array<string, RoleDefinition> */
    private array $roles = [];

    /** @var array<string, GroupKey> */
    private array $primary = [];

    /** @var array<string, array<string, GroupKey>> */
    private array $secondary = [];

    /** @var array<string, array<string, RoleAssignment>> */
    private array $assignments = [];

    public function findGroup(GroupKey $key): ?GroupDefinition
    {
        return $this->groups[$key->value()] ?? null;
    }

    public function findRole(RoleKey $key): ?RoleDefinition
    {
        return $this->roles[$key->value()] ?? null;
    }

    public function saveGroup(
        GroupDefinition $group,
        ?EntityId $actorId,
        ?string $reasonCode,
        DateTimeImmutable $now,
    ): void {
        unset($actorId, $reasonCode, $now);
        $this->groups[$group->key->value()] = $group;
    }

    public function saveRole(
        RoleDefinition $role,
        ?EntityId $actorId,
        ?string $reasonCode,
        DateTimeImmutable $now,
    ): void {
        unset($actorId, $reasonCode, $now);
        $this->roles[$role->key->value()] = $role;
    }

    public function membershipFor(EntityId $userId): UserAccessMembership
    {
        UserId::assert($userId);
        $primary = $this->primary[$userId->value()] ?? null;
        if ($primary === null) {
            throw new AccessException('Test user membership is unavailable.');
        }
        return new UserAccessMembership(
            $userId,
            $primary,
            array_values($this->secondary[$userId->value()] ?? []),
            array_values($this->assignments[$userId->value()] ?? []),
        );
    }

    public function setPrimaryGroup(
        EntityId $userId,
        GroupKey $group,
        ?EntityId $actorId,
        ?string $reasonCode,
        DateTimeImmutable $now,
    ): bool {
        unset($actorId, $reasonCode, $now);
        $current = $this->primary[$userId->value()] ?? null;
        if ($current !== null && $current->equals($group)) {
            return false;
        }
        $this->primary[$userId->value()] = $group;
        unset($this->secondary[$userId->value()][$group->value()]);
        return true;
    }

    public function addSecondaryGroup(
        EntityId $userId,
        GroupKey $group,
        ?EntityId $actorId,
        ?string $reasonCode,
        DateTimeImmutable $now,
    ): bool {
        unset($actorId, $reasonCode, $now);
        if (isset($this->secondary[$userId->value()][$group->value()])) {
            return false;
        }
        $this->secondary[$userId->value()][$group->value()] = $group;
        return true;
    }

    public function removeSecondaryGroup(
        EntityId $userId,
        GroupKey $group,
        ?EntityId $actorId,
        ?string $reasonCode,
        DateTimeImmutable $now,
    ): bool {
        unset($actorId, $reasonCode, $now);
        if (!isset($this->secondary[$userId->value()][$group->value()])) {
            return false;
        }
        unset($this->secondary[$userId->value()][$group->value()]);
        return true;
    }

    public function assignRole(
        EntityId $userId,
        RoleKey $role,
        RoleAssignmentSource $source,
        ?EntityId $actorId,
        ?string $reasonCode,
        DateTimeImmutable $now,
    ): bool {
        unset($reasonCode);
        if (isset($this->assignments[$userId->value()][$role->value()])) {
            return false;
        }
        $this->assignments[$userId->value()][$role->value()] = new RoleAssignment(
            $role,
            $source,
            $now,
            $actorId,
        );
        return true;
    }

    public function revokeRole(
        EntityId $userId,
        RoleKey $role,
        RoleAssignmentSource $source,
        ?EntityId $actorId,
        ?string $reasonCode,
        DateTimeImmutable $now,
    ): bool {
        unset($source, $actorId, $reasonCode, $now);
        if (!isset($this->assignments[$userId->value()][$role->value()])) {
            return false;
        }
        unset($this->assignments[$userId->value()][$role->value()]);
        return true;
    }

    public function seedGroup(GroupDefinition $group): void
    {
        $this->groups[$group->key->value()] = $group;
    }

    public function seedRole(RoleDefinition $role): void
    {
        $this->roles[$role->key->value()] = $role;
    }

    public function seedUser(EntityId $userId, GroupKey $primary): void
    {
        UserId::assert($userId);
        $this->primary[$userId->value()] = $primary;
    }
}
