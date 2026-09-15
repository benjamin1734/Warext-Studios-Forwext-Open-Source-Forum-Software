<?php

declare(strict_types=1);

namespace Forwext\Core\Access;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use RuntimeException;

final readonly class DatabaseAccessDirectoryStore implements AccessDirectoryStore
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function findGroup(GroupKey $key): ?GroupDefinition
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT `group_key`,`name`,`description`,`is_system`,`is_active`,`sort_order` '
            . 'FROM `forwext_groups` WHERE `group_key`=:group_key LIMIT 1',
            ['group_key' => $key->value()],
        ));
        return $row === null ? null : $this->group($row);
    }

    public function findRole(RoleKey $key): ?RoleDefinition
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT `role_key`,`name`,`description`,`role_kind`,`is_active`,`sort_order` '
            . 'FROM `forwext_roles` WHERE `role_key`=:role_key LIMIT 1',
            ['role_key' => $key->value()],
        ));
        return $row === null ? null : $this->role($row);
    }

    public function saveGroup(
        GroupDefinition $group,
        ?EntityId $actorId,
        ?string $reasonCode,
        DateTimeImmutable $now,
    ): void {
        $actorId = $this->assertActor($actorId);
        $now = self::utc($now);

        $this->database->transaction(function (TransactionalQueryExecutor $database) use (
            $group,
            $actorId,
            $reasonCode,
            $now,
        ): void {
            $existing = $database->fetchOne(new CompiledQuery(
                'SELECT `is_system` FROM `forwext_groups` WHERE `group_key`=:group_key FOR UPDATE',
                ['group_key' => $group->key->value()],
            ));
            if ($existing !== null && (bool) $existing['is_system'] !== $group->system) {
                throw new AccessException('Stored group system classification cannot be changed.');
            }

            if ($existing === null) {
                $database->execute(new CompiledQuery(
                    'INSERT INTO `forwext_groups` '
                    . '(`group_key`,`name`,`description`,`is_system`,`is_active`,`sort_order`,`created_at_utc`,`updated_at_utc`) '
                    . 'VALUES (:group_key,:name,:description,:is_system,:is_active,:sort_order,:created_at,:updated_at)',
                    [
                        'group_key' => $group->key->value(),
                        'name' => $group->name,
                        'description' => $group->description,
                        'is_system' => $group->system,
                        'is_active' => $group->active,
                        'sort_order' => $group->sortOrder,
                        'created_at' => self::format($now),
                        'updated_at' => self::format($now),
                    ],
                ));
                $event = 'group.created';
            } else {
                $database->execute(new CompiledQuery(
                    'UPDATE `forwext_groups` SET `name`=:name,`description`=:description,'
                    . '`is_active`=:is_active,`sort_order`=:sort_order,`updated_at_utc`=:updated_at '
                    . 'WHERE `group_key`=:group_key',
                    [
                        'group_key' => $group->key->value(),
                        'name' => $group->name,
                        'description' => $group->description,
                        'is_active' => $group->active,
                        'sort_order' => $group->sortOrder,
                        'updated_at' => self::format($now),
                    ],
                ));
                $event = 'group.updated';
            }

            $this->appendHistory(
                $database,
                $event,
                null,
                'group',
                $group->key->value(),
                null,
                null,
                $actorId,
                $reasonCode,
                $now,
            );
        });
    }

    public function saveRole(
        RoleDefinition $role,
        ?EntityId $actorId,
        ?string $reasonCode,
        DateTimeImmutable $now,
    ): void {
        $actorId = $this->assertActor($actorId);
        $now = self::utc($now);

        $this->database->transaction(function (TransactionalQueryExecutor $database) use (
            $role,
            $actorId,
            $reasonCode,
            $now,
        ): void {
            $existing = $database->fetchOne(new CompiledQuery(
                'SELECT `role_kind` FROM `forwext_roles` WHERE `role_key`=:role_key FOR UPDATE',
                ['role_key' => $role->key->value()],
            ));
            if ($existing !== null && (string) $existing['role_kind'] !== $role->kind->value) {
                throw new AccessException('Stored role kind cannot be changed.');
            }

            if ($existing === null) {
                $database->execute(new CompiledQuery(
                    'INSERT INTO `forwext_roles` '
                    . '(`role_key`,`name`,`description`,`role_kind`,`is_active`,`sort_order`,`created_at_utc`,`updated_at_utc`) '
                    . 'VALUES (:role_key,:name,:description,:role_kind,:is_active,:sort_order,:created_at,:updated_at)',
                    [
                        'role_key' => $role->key->value(),
                        'name' => $role->name,
                        'description' => $role->description,
                        'role_kind' => $role->kind->value,
                        'is_active' => $role->active,
                        'sort_order' => $role->sortOrder,
                        'created_at' => self::format($now),
                        'updated_at' => self::format($now),
                    ],
                ));
                $event = 'role.created';
            } else {
                $database->execute(new CompiledQuery(
                    'UPDATE `forwext_roles` SET `name`=:name,`description`=:description,'
                    . '`is_active`=:is_active,`sort_order`=:sort_order,`updated_at_utc`=:updated_at '
                    . 'WHERE `role_key`=:role_key',
                    [
                        'role_key' => $role->key->value(),
                        'name' => $role->name,
                        'description' => $role->description,
                        'is_active' => $role->active,
                        'sort_order' => $role->sortOrder,
                        'updated_at' => self::format($now),
                    ],
                ));
                $event = 'role.updated';
            }

            $this->appendHistory(
                $database,
                $event,
                null,
                'role',
                $role->key->value(),
                null,
                null,
                $actorId,
                $reasonCode,
                $now,
            );
        });
    }

    public function membershipFor(EntityId $userId): UserAccessMembership
    {
        UserId::assert($userId);
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT `primary_group_key` FROM `forwext_users` WHERE `user_id`=:user_id LIMIT 1',
            ['user_id' => $userId->value()],
        ));
        if ($row === null || !is_string($row['primary_group_key'] ?? null)) {
            throw new AccessException('User access membership is unavailable.');
        }

        $secondaryRows = $this->database->fetchAll(new CompiledQuery(
            'SELECT `group_key` FROM `forwext_user_secondary_groups` '
            . 'WHERE `user_id`=:user_id ORDER BY `group_key`',
            ['user_id' => $userId->value()],
        ));
        $secondary = [];
        foreach ($secondaryRows as $secondaryRow) {
            if (!is_string($secondaryRow['group_key'] ?? null)) {
                throw new RuntimeException('Stored secondary group key is invalid.');
            }
            $secondary[] = GroupKey::fromString($secondaryRow['group_key']);
        }

        $roleRows = $this->database->fetchAll(new CompiledQuery(
            'SELECT `role_key`,`assignment_source`,`assigned_at_utc`,`assigned_by_user_id` '
            . 'FROM `forwext_user_roles` WHERE `user_id`=:user_id ORDER BY `role_key`',
            ['user_id' => $userId->value()],
        ));
        $roles = [];
        foreach ($roleRows as $roleRow) {
            if (
                !is_string($roleRow['role_key'] ?? null)
                || !is_string($roleRow['assignment_source'] ?? null)
                || !is_string($roleRow['assigned_at_utc'] ?? null)
            ) {
                throw new RuntimeException('Stored role assignment is invalid.');
            }
            $actor = isset($roleRow['assigned_by_user_id']) && is_string($roleRow['assigned_by_user_id'])
                ? UserId::fromStored($roleRow['assigned_by_user_id'])
                : null;
            $roles[] = new RoleAssignment(
                RoleKey::fromString($roleRow['role_key']),
                RoleAssignmentSource::from($roleRow['assignment_source']),
                self::parse($roleRow['assigned_at_utc']),
                $actor,
            );
        }

        return new UserAccessMembership(
            $userId,
            GroupKey::fromString($row['primary_group_key']),
            $secondary,
            $roles,
        );
    }

    public function setPrimaryGroup(
        EntityId $userId,
        GroupKey $group,
        ?EntityId $actorId,
        ?string $reasonCode,
        DateTimeImmutable $now,
    ): bool {
        UserId::assert($userId);
        $actorId = $this->assertActor($actorId);
        $now = self::utc($now);

        return $this->database->transaction(function (TransactionalQueryExecutor $database) use (
            $userId,
            $group,
            $actorId,
            $reasonCode,
            $now,
        ): bool {
            $row = $database->fetchOne(new CompiledQuery(
                'SELECT `primary_group_key` FROM `forwext_users` WHERE `user_id`=:user_id FOR UPDATE',
                ['user_id' => $userId->value()],
            ));
            if ($row === null || !is_string($row['primary_group_key'] ?? null)) {
                throw new AccessException('User access membership is unavailable.');
            }
            $previous = $row['primary_group_key'];
            if (hash_equals($previous, $group->value())) {
                return false;
            }

            $affected = $database->execute(new CompiledQuery(
                'UPDATE `forwext_users` SET `primary_group_key`=:group_key WHERE `user_id`=:user_id',
                ['group_key' => $group->value(), 'user_id' => $userId->value()],
            ));
            if ($affected !== 1) {
                throw new AccessException('Unable to update primary group.');
            }
            $database->execute(new CompiledQuery(
                'DELETE FROM `forwext_user_secondary_groups` '
                . 'WHERE `user_id`=:user_id AND `group_key`=:group_key',
                ['user_id' => $userId->value(), 'group_key' => $group->value()],
            ));

            $this->appendHistory(
                $database,
                'group.primary.changed',
                $userId,
                'group',
                $group->value(),
                $previous,
                null,
                $actorId,
                $reasonCode,
                $now,
            );
            return true;
        });
    }

    public function addSecondaryGroup(
        EntityId $userId,
        GroupKey $group,
        ?EntityId $actorId,
        ?string $reasonCode,
        DateTimeImmutable $now,
    ): bool {
        UserId::assert($userId);
        $actorId = $this->assertActor($actorId);
        $now = self::utc($now);

        return $this->database->transaction(function (TransactionalQueryExecutor $database) use (
            $userId,
            $group,
            $actorId,
            $reasonCode,
            $now,
        ): bool {
            $row = $database->fetchOne(new CompiledQuery(
                'SELECT `primary_group_key` FROM `forwext_users` WHERE `user_id`=:user_id FOR UPDATE',
                ['user_id' => $userId->value()],
            ));
            if ($row === null || !is_string($row['primary_group_key'] ?? null)) {
                throw new AccessException('User access membership is unavailable.');
            }
            if (hash_equals($row['primary_group_key'], $group->value())) {
                throw new AccessException('Primary group cannot also be assigned as a secondary group.');
            }

            $affected = $database->execute(new CompiledQuery(
                'INSERT IGNORE INTO `forwext_user_secondary_groups` '
                . '(`user_id`,`group_key`,`assigned_at_utc`,`assigned_by_user_id`) '
                . 'VALUES (:user_id,:group_key,:assigned_at,:assigned_by)',
                [
                    'user_id' => $userId->value(),
                    'group_key' => $group->value(),
                    'assigned_at' => self::format($now),
                    'assigned_by' => $actorId?->value(),
                ],
            ));
            if ($affected === 0) {
                return false;
            }

            $this->appendHistory(
                $database,
                'group.secondary.assigned',
                $userId,
                'group',
                $group->value(),
                null,
                null,
                $actorId,
                $reasonCode,
                $now,
            );
            return true;
        });
    }

    public function removeSecondaryGroup(
        EntityId $userId,
        GroupKey $group,
        ?EntityId $actorId,
        ?string $reasonCode,
        DateTimeImmutable $now,
    ): bool {
        UserId::assert($userId);
        $actorId = $this->assertActor($actorId);
        $now = self::utc($now);

        return $this->database->transaction(function (TransactionalQueryExecutor $database) use (
            $userId,
            $group,
            $actorId,
            $reasonCode,
            $now,
        ): bool {
            $affected = $database->execute(new CompiledQuery(
                'DELETE FROM `forwext_user_secondary_groups` '
                . 'WHERE `user_id`=:user_id AND `group_key`=:group_key',
                ['user_id' => $userId->value(), 'group_key' => $group->value()],
            ));
            if ($affected === 0) {
                return false;
            }

            $this->appendHistory(
                $database,
                'group.secondary.revoked',
                $userId,
                'group',
                $group->value(),
                null,
                null,
                $actorId,
                $reasonCode,
                $now,
            );
            return true;
        });
    }

    public function assignRole(
        EntityId $userId,
        RoleKey $role,
        RoleAssignmentSource $source,
        ?EntityId $actorId,
        ?string $reasonCode,
        DateTimeImmutable $now,
    ): bool {
        UserId::assert($userId);
        $actorId = $this->assertActor($actorId);
        $now = self::utc($now);

        return $this->database->transaction(function (TransactionalQueryExecutor $database) use (
            $userId,
            $role,
            $source,
            $actorId,
            $reasonCode,
            $now,
        ): bool {
            $affected = $database->execute(new CompiledQuery(
                'INSERT IGNORE INTO `forwext_user_roles` '
                . '(`user_id`,`role_key`,`assignment_source`,`assigned_at_utc`,`assigned_by_user_id`) '
                . 'VALUES (:user_id,:role_key,:source,:assigned_at,:assigned_by)',
                [
                    'user_id' => $userId->value(),
                    'role_key' => $role->value(),
                    'source' => $source->value,
                    'assigned_at' => self::format($now),
                    'assigned_by' => $actorId?->value(),
                ],
            ));
            if ($affected === 0) {
                return false;
            }

            $this->appendHistory(
                $database,
                'role.assigned',
                $userId,
                'role',
                $role->value(),
                null,
                $source,
                $actorId,
                $reasonCode,
                $now,
            );
            return true;
        });
    }

    public function revokeRole(
        EntityId $userId,
        RoleKey $role,
        RoleAssignmentSource $source,
        ?EntityId $actorId,
        ?string $reasonCode,
        DateTimeImmutable $now,
    ): bool {
        UserId::assert($userId);
        $actorId = $this->assertActor($actorId);
        $now = self::utc($now);

        return $this->database->transaction(function (TransactionalQueryExecutor $database) use (
            $userId,
            $role,
            $source,
            $actorId,
            $reasonCode,
            $now,
        ): bool {
            $affected = $database->execute(new CompiledQuery(
                'DELETE FROM `forwext_user_roles` WHERE `user_id`=:user_id AND `role_key`=:role_key',
                ['user_id' => $userId->value(), 'role_key' => $role->value()],
            ));
            if ($affected === 0) {
                return false;
            }

            $this->appendHistory(
                $database,
                'role.revoked',
                $userId,
                'role',
                $role->value(),
                null,
                $source,
                $actorId,
                $reasonCode,
                $now,
            );
            return true;
        });
    }

    private function appendHistory(
        TransactionalQueryExecutor $database,
        string $eventType,
        ?EntityId $targetUserId,
        string $subjectType,
        string $subjectKey,
        ?string $previousSubjectKey,
        ?RoleAssignmentSource $source,
        ?EntityId $actorId,
        ?string $reasonCode,
        DateTimeImmutable $now,
    ): void {
        $database->execute(new CompiledQuery(
            'INSERT INTO `forwext_access_history` '
            . '(`event_type`,`target_user_id`,`subject_type`,`subject_key`,`previous_subject_key`,'
            . '`assignment_source`,`actor_user_id`,`reason_code`,`occurred_at_utc`) '
            . 'VALUES (:event_type,:target_user_id,:subject_type,:subject_key,:previous_subject_key,'
            . ':assignment_source,:actor_user_id,:reason_code,:occurred_at)',
            [
                'event_type' => $eventType,
                'target_user_id' => $targetUserId?->value(),
                'subject_type' => $subjectType,
                'subject_key' => $subjectKey,
                'previous_subject_key' => $previousSubjectKey,
                'assignment_source' => $source?->value,
                'actor_user_id' => $actorId?->value(),
                'reason_code' => $reasonCode,
                'occurred_at' => self::format($now),
            ],
        ));
    }

    /** @param array<string,mixed> $row */
    private function group(array $row): GroupDefinition
    {
        return new GroupDefinition(
            GroupKey::fromString((string) $row['group_key']),
            (string) $row['name'],
            (string) $row['description'],
            (bool) $row['is_system'],
            (bool) $row['is_active'],
            (int) $row['sort_order'],
        );
    }

    /** @param array<string,mixed> $row */
    private function role(array $row): RoleDefinition
    {
        return new RoleDefinition(
            RoleKey::fromString((string) $row['role_key']),
            (string) $row['name'],
            RoleKind::from((string) $row['role_kind']),
            (string) $row['description'],
            (bool) $row['is_active'],
            (int) $row['sort_order'],
        );
    }

    private function assertActor(?EntityId $actorId): ?EntityId
    {
        if ($actorId !== null) {
            UserId::assert($actorId);
        }
        return $actorId;
    }

    private static function utc(DateTimeImmutable $value): DateTimeImmutable
    {
        return $value->setTimezone(new DateTimeZone('UTC'));
    }

    private static function format(DateTimeImmutable $value): string
    {
        return self::utc($value)->format('Y-m-d H:i:s.u');
    }

    private static function parse(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (!$date instanceof DateTimeImmutable) {
            throw new RuntimeException('Stored access timestamp is invalid.');
        }
        return $date;
    }
}
