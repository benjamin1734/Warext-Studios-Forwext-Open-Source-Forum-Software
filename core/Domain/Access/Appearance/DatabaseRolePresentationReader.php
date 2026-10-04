<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Access\Appearance;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use Forwext\Core\Domain\Access\AccessIdentifier;
use Forwext\Core\Domain\Access\Role;
use Forwext\Core\Domain\Access\RoleKind;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class DatabaseRolePresentationReader
{
    public function __construct(
        private QueryExecutor $database,
        private DatabaseRoleAppearanceRepository $appearances,
    ) {
    }

    /**
     * @param list<EntityId> $roleIds
     * @return array<string,RolePresentation>
     */
    public function findMany(array $roleIds): array
    {
        if (count($roleIds) > 200) {
            throw new InvalidArgumentException('Batch role presentation lookup accepts at most 200 roles.');
        }

        $unique = [];
        foreach ($roleIds as $roleId) {
            if (!$roleId instanceof EntityId) {
                throw new InvalidArgumentException('Batch role presentation ids must be entity ids.');
            }
            $unique[$roleId->value()] = $roleId;
        }
        if ($unique === []) {
            return [];
        }

        $parameters = [];
        $placeholders = [];
        foreach (array_values($unique) as $index => $roleId) {
            $name = 'role_' . $index;
            $placeholders[] = ':' . $name;
            $parameters[$name] = $roleId->value();
        }

        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT `role_id`,`role_key`,`name`,`kind`,`is_protected`,`priority` '
            . 'FROM `forwext_roles` WHERE `role_id` IN (' . implode(',', $placeholders) . ') '
            . 'ORDER BY `priority` DESC,`name`,`role_id`',
            $parameters,
        ));
        $appearances = $this->appearances->findMany(array_values($unique));

        $presentations = [];
        foreach ($rows as $row) {
            $roleId = EntityId::fromString((string) $row['role_id']);
            $appearance = $appearances[$roleId->value()] ?? null;
            if ($appearance === null) {
                continue;
            }
            $role = new Role(
                $roleId,
                AccessIdentifier::fromString((string) $row['role_key']),
                (string) $row['name'],
                RoleKind::from((string) $row['kind']),
                (bool) $row['is_protected'],
                (int) $row['priority'],
            );
            $presentations[$roleId->value()] = new RolePresentation($role, $appearance);
        }

        return $presentations;
    }
}
