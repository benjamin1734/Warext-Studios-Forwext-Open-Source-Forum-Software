<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Access;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;

final readonly class DatabaseUserAccessAssignmentProvider implements UserAccessAssignmentProvider
{
    public const UNASSIGNED_GROUP_ID = 'system:unassigned';

    public function __construct(private QueryExecutor $database)
    {
    }

    public function find(EntityId $userId): ?UserAccessAssignment
    {
        UserId::assert($userId);
        $primary = $this->database->fetchOne(new CompiledQuery(
            'SELECT `group_id` FROM `forwext_user_primary_groups` WHERE `user_id` = :user_id LIMIT 1',
            ['user_id' => $userId->value()],
        ));

        if ($primary === null) {
            $exists = $this->database->fetchValue(new CompiledQuery(
                'SELECT COUNT(*) FROM `forwext_users` WHERE `user_id` = :user_id',
                ['user_id' => $userId->value()],
            ));
            if ((int) $exists !== 1) {
                return null;
            }

            return new UserAccessAssignment(
                $userId,
                EntityId::fromString(self::UNASSIGNED_GROUP_ID),
            );
        }

        $secondary = $this->database->fetchAll(new CompiledQuery(
            'SELECT `group_id` FROM `forwext_user_secondary_groups` WHERE `user_id` = :user_id ORDER BY `group_id`',
            ['user_id' => $userId->value()],
        ));
        $roles = $this->database->fetchAll(new CompiledQuery(
            'SELECT `role_id` FROM `forwext_user_role_assignments` WHERE `user_id` = :user_id ORDER BY `role_id`',
            ['user_id' => $userId->value()],
        ));

        return new UserAccessAssignment(
            $userId,
            EntityId::fromString((string) $primary['group_id']),
            array_map(static fn (array $row): EntityId => EntityId::fromString((string) $row['group_id']), $secondary),
            array_map(static fn (array $row): EntityId => EntityId::fromString((string) $row['role_id']), $roles),
        );
    }
}
