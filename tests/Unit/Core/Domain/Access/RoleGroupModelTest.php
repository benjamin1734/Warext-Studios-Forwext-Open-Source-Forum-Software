<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Domain\Access;

use Forwext\Core\Domain\Access\AccessIdentifier;
use Forwext\Core\Domain\Access\Role;
use Forwext\Core\Domain\Access\RoleKind;
use Forwext\Core\Domain\Access\UserAccessAssignment;
use Forwext\Core\Domain\Access\UserGroup;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RoleGroupModelTest extends TestCase
{
    public function testRoleAndGroupRemainDistinctDomainConcepts(): void
    {
        $group = new UserGroup($this->id('group'), AccessIdentifier::fromString('registered'), 'Registered', true, 10);
        $role = new Role($this->id('role'), AccessIdentifier::fromString('moderator'), 'Moderator', RoleKind::Staff, false, 100);

        self::assertTrue($group->isSystem());
        self::assertTrue($role->isStaff());
        self::assertFalse($role->isSystem());
        self::assertSame('registered', $group->key()->value());
        self::assertSame('moderator', $role->key()->value());
    }

    public function testSystemRoleMustBeProtected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Role($this->id('role'), AccessIdentifier::fromString('administrator'), 'Administrator', RoleKind::System, false, 1000);
    }

    public function testUserHasExactlyOnePrimaryGroupAndDeduplicatedSecondaryGroupsAndRoles(): void
    {
        $primary = $this->id('primary');
        $secondary = $this->id('secondary');
        $role = $this->id('role');
        $assignment = new UserAccessAssignment($this->id('user'), $primary);

        $assignment->addSecondaryGroup($secondary);
        $assignment->addSecondaryGroup($secondary);
        $assignment->assignRole($role);
        $assignment->assignRole($role);

        self::assertTrue($assignment->primaryGroupId()->equals($primary));
        self::assertCount(1, $assignment->secondaryGroupIds());
        self::assertCount(1, $assignment->roleIds());

        $assignment->changePrimaryGroup($secondary);
        self::assertTrue($assignment->primaryGroupId()->equals($secondary));
        self::assertSame([], $assignment->secondaryGroupIds());
    }

    public function testPrimaryGroupCannotAlsoBeSecondary(): void
    {
        $primary = $this->id('primary');
        $assignment = new UserAccessAssignment($this->id('user'), $primary);

        $this->expectException(InvalidArgumentException::class);
        $assignment->addSecondaryGroup($primary);
    }

    private function id(string $suffix): EntityId
    {
        return EntityId::fromString('access:' . $suffix);
    }
}
