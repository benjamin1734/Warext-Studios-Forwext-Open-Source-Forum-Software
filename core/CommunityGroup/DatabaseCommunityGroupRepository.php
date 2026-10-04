<?php

declare(strict_types=1);

namespace Forwext\Core\CommunityGroup;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;
use RuntimeException;

final readonly class DatabaseCommunityGroupRepository implements CommunityGroupRepository
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function directory(?string $query = null, int $limit = 30, int $offset = 0): array
    {
        self::pagination($limit, $offset);
        $where = ["g.state='active'"];
        $parameters = [];
        if ($query !== null) {
            $query = trim($query);
            if ($query === '' || mb_strlen($query) > 120) {
                throw new InvalidArgumentException('Community group search query is invalid.');
            }
            $where[] = '(g.name LIKE :query_name OR g.tagline LIKE :query_tagline)';
            $parameters['query_name'] = '%' . $query . '%';
            $parameters['query_tagline'] = '%' . $query . '%';
        }
        return array_map($this->hydrateGroup(...), $this->database->fetchAll(new CompiledQuery(
            $this->groupSelect() . ' WHERE ' . implode(' AND ', $where)
            . ' GROUP BY g.group_id,g.owner_user_id,g.slug,g.name,g.tagline,g.description,g.join_policy,g.state,'
            . 'g.created_at_utc,g.updated_at_utc '
            . 'ORDER BY active_member_count DESC,g.updated_at_utc DESC,g.group_id DESC '
            . 'LIMIT ' . $limit . ' OFFSET ' . $offset,
            $parameters,
        )));
    }

    public function mine(EntityId $userId, int $limit = 100): array
    {
        UserId::assert($userId);
        if ($limit < 1 || $limit > 200) {
            throw new InvalidArgumentException('Community group mine limit is invalid.');
        }
        return array_map($this->hydrateGroup(...), $this->database->fetchAll(new CompiledQuery(
            $this->groupSelect()
            . ' INNER JOIN forwext_group_members mine ON mine.group_id=g.group_id AND mine.user_id=:user_id '
            . "WHERE mine.state IN ('active','pending') "
            . 'GROUP BY g.group_id,g.owner_user_id,g.slug,g.name,g.tagline,g.description,g.join_policy,g.state,'
            . 'g.created_at_utc,g.updated_at_utc '
            . "ORDER BY (mine.role_key='owner') DESC,(mine.state='active') DESC,g.updated_at_utc DESC,g.group_id DESC "
            . 'LIMIT ' . $limit,
            ['user_id'=>$userId->value()],
        )));
    }

    public function byId(EntityId $groupId): ?CommunityGroup
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            $this->groupSelect() . ' WHERE g.group_id=:group_id '
            . 'GROUP BY g.group_id,g.owner_user_id,g.slug,g.name,g.tagline,g.description,g.join_policy,g.state,'
            . 'g.created_at_utc,g.updated_at_utc LIMIT 1',
            ['group_id'=>$groupId->value()],
        ));
        return $row === null ? null : $this->hydrateGroup($row);
    }

    public function bySlug(string $slug): ?CommunityGroup
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            $this->groupSelect() . ' WHERE g.slug=:slug '
            . 'GROUP BY g.group_id,g.owner_user_id,g.slug,g.name,g.tagline,g.description,g.join_policy,g.state,'
            . 'g.created_at_utc,g.updated_at_utc LIMIT 1',
            ['slug'=>$slug],
        ));
        return $row === null ? null : $this->hydrateGroup($row);
    }

    public function create(CommunityGroup $group, CommunityGroupMember $owner): void
    {
        if (!$group->groupId->equals($owner->groupId)
            || !$group->ownerUserId->equals($owner->userId)
            || $owner->roleKey !== 'owner'
            || !$owner->active()) {
            throw new InvalidArgumentException('Community group owner membership is inconsistent.');
        }
        $this->database->transaction(function (TransactionalQueryExecutor $database) use ($group,$owner): void {
            $database->execute(new CompiledQuery(
                'INSERT INTO forwext_groups '
                . '(group_id,owner_user_id,slug,name,tagline,description,join_policy,state,created_at_utc,updated_at_utc) '
                . 'VALUES (:group_id,:owner,:slug,:name,:tagline,:description,:join_policy,:state,:created_at,:updated_at)',
                [
                    'group_id'=>$group->groupId->value(),
                    'owner'=>$group->ownerUserId->value(),
                    'slug'=>$group->slug,
                    'name'=>$group->name,
                    'tagline'=>$group->tagline,
                    'description'=>$group->description,
                    'join_policy'=>$group->joinPolicy,
                    'state'=>$group->state,
                    'created_at'=>self::format($group->createdAt),
                    'updated_at'=>self::format($group->updatedAt),
                ],
                true,
            ));
            $database->execute(new CompiledQuery(
                'INSERT INTO forwext_group_members '
                . '(group_id,user_id,role_key,state,acted_by_user_id,created_at_utc,updated_at_utc) '
                . "VALUES (:group_id,:user_id,'owner','active',:actor,:created_at,:updated_at)",
                [
                    'group_id'=>$owner->groupId->value(),
                    'user_id'=>$owner->userId->value(),
                    'actor'=>$owner->actedByUserId?->value(),
                    'created_at'=>self::format($owner->createdAt),
                    'updated_at'=>self::format($owner->updatedAt),
                ],
                true,
            ));
        });
    }

    public function members(EntityId $groupId, bool $includePending = false, int $limit = 200): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('Community group member limit is invalid.');
        }
        $where = $includePending ? '' : " AND state='active'";
        return array_map($this->hydrateMember(...), $this->database->fetchAll(new CompiledQuery(
            'SELECT group_id,user_id,role_key,state,acted_by_user_id,created_at_utc,updated_at_utc '
            . 'FROM forwext_group_members WHERE group_id=:group_id' . $where
            . " ORDER BY FIELD(role_key,'owner','moderator','member'),FIELD(state,'active','pending'),created_at_utc ASC,user_id ASC "
            . 'LIMIT ' . $limit,
            ['group_id'=>$groupId->value()],
        )));
    }

    public function membership(EntityId $groupId, EntityId $userId): ?CommunityGroupMember
    {
        UserId::assert($userId);
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT group_id,user_id,role_key,state,acted_by_user_id,created_at_utc,updated_at_utc '
            . 'FROM forwext_group_members WHERE group_id=:group_id AND user_id=:user_id LIMIT 1',
            ['group_id'=>$groupId->value(),'user_id'=>$userId->value()],
        ));
        return $row === null ? null : $this->hydrateMember($row);
    }

    public function requestMembership(
        EntityId $groupId,
        EntityId $userId,
        string $targetState,
        DateTimeImmutable $now,
    ): void {
        UserId::assert($userId);
        if (!in_array($targetState, ['active','pending'], true)) {
            throw new InvalidArgumentException('Community group membership request state is invalid.');
        }
        $this->database->transaction(function (TransactionalQueryExecutor $database) use (
            $groupId,$userId,$targetState,$now,
        ): void {
            $group = $database->fetchOne(new CompiledQuery(
                'SELECT owner_user_id,state,join_policy FROM forwext_groups WHERE group_id=:group_id FOR UPDATE',
                ['group_id'=>$groupId->value()],
            ));
            if ($group === null || (string) $group['state'] !== 'active') {
                throw new InvalidArgumentException('Community group is unavailable.');
            }
            if (hash_equals((string) $group['owner_user_id'], $userId->value())) {
                throw new InvalidArgumentException('Community group owner is already a member.');
            }
            $policy = (string) $group['join_policy'];
            $expected = $policy === 'open' ? 'active' : ($policy === 'approval' ? 'pending' : null);
            if ($expected === null || $expected !== $targetState) {
                throw new InvalidArgumentException('Community group cannot be joined with this state.');
            }
            $database->execute(new CompiledQuery(
                'INSERT INTO forwext_group_members '
                . "(group_id,user_id,role_key,state,acted_by_user_id,created_at_utc,updated_at_utc) "
                . "VALUES (:group_id,:user_id,'member',:state,:actor,:created_at,:updated_at) "
                . "ON DUPLICATE KEY UPDATE state=IF(state='active','active',VALUES(state)),"
                . "role_key=IF(role_key='owner','owner','member'),acted_by_user_id=VALUES(acted_by_user_id),"
                . 'updated_at_utc=VALUES(updated_at_utc)',
                [
                    'group_id'=>$groupId->value(),
                    'user_id'=>$userId->value(),
                    'state'=>$targetState,
                    'actor'=>$userId->value(),
                    'created_at'=>self::format($now),
                    'updated_at'=>self::format($now),
                ],
                true,
            ));
        });
    }

    public function setMembership(
        EntityId $groupId,
        EntityId $userId,
        string $roleKey,
        string $state,
        EntityId $actorUserId,
        DateTimeImmutable $now,
    ): void {
        UserId::assert($userId);
        UserId::assert($actorUserId);
        if (!in_array($roleKey, ['moderator','member'], true) || $state !== 'active') {
            throw new InvalidArgumentException('Community group managed membership is invalid.');
        }
        $this->database->transaction(function (TransactionalQueryExecutor $database) use (
            $groupId,$userId,$roleKey,$actorUserId,$now,
        ): void {
            $group = $database->fetchOne(new CompiledQuery(
                'SELECT owner_user_id,state FROM forwext_groups WHERE group_id=:group_id FOR UPDATE',
                ['group_id'=>$groupId->value()],
            ));
            if ($group === null || (string) $group['state'] !== 'active') {
                throw new InvalidArgumentException('Community group is unavailable.');
            }
            if (hash_equals((string) $group['owner_user_id'], $userId->value())) {
                throw new InvalidArgumentException('Community group owner role cannot be changed.');
            }
            $affected = $database->execute(new CompiledQuery(
                "UPDATE forwext_group_members SET role_key=:role_key,state='active',acted_by_user_id=:actor,"
                . 'updated_at_utc=:updated_at WHERE group_id=:group_id AND user_id=:user_id',
                [
                    'role_key'=>$roleKey,
                    'actor'=>$actorUserId->value(),
                    'updated_at'=>self::format($now),
                    'group_id'=>$groupId->value(),
                    'user_id'=>$userId->value(),
                ],
                true,
            ));
            if ($affected !== 1) {
                throw new InvalidArgumentException('Community group membership was not found.');
            }
        });
    }

    public function removeMembership(
        EntityId $groupId,
        EntityId $userId,
        EntityId $actorUserId,
        DateTimeImmutable $now,
    ): bool {
        UserId::assert($userId);
        UserId::assert($actorUserId);
        return $this->database->transaction(function (TransactionalQueryExecutor $database) use (
            $groupId,$userId,
        ): bool {
            $group = $database->fetchOne(new CompiledQuery(
                'SELECT owner_user_id FROM forwext_groups WHERE group_id=:group_id FOR UPDATE',
                ['group_id'=>$groupId->value()],
            ));
            if ($group === null) {
                throw new InvalidArgumentException('Community group was not found.');
            }
            if (hash_equals((string) $group['owner_user_id'], $userId->value())) {
                throw new InvalidArgumentException('Community group owner cannot leave or be removed.');
            }
            return $database->execute(new CompiledQuery(
                'DELETE FROM forwext_group_members WHERE group_id=:group_id AND user_id=:user_id',
                ['group_id'=>$groupId->value(),'user_id'=>$userId->value()],
                true,
            )) === 1;
        });
    }

    private function groupSelect(): string
    {
        return 'SELECT g.group_id,g.owner_user_id,g.slug,g.name,g.tagline,g.description,g.join_policy,g.state,'
            . 'g.created_at_utc,g.updated_at_utc,'
            . "COALESCE(SUM(CASE WHEN m.state='active' THEN 1 ELSE 0 END),0) AS active_member_count,"
            . "COALESCE(SUM(CASE WHEN m.state='pending' THEN 1 ELSE 0 END),0) AS pending_member_count "
            . 'FROM forwext_groups g LEFT JOIN forwext_group_members m ON m.group_id=g.group_id';
    }

    /** @param array<string,mixed> $row */
    private function hydrateGroup(array $row): CommunityGroup
    {
        return new CommunityGroup(
            EntityId::fromString((string) $row['group_id']),
            UserId::fromStored((string) $row['owner_user_id']),
            (string) $row['slug'],
            (string) $row['name'],
            (string) $row['tagline'],
            (string) $row['description'],
            (string) $row['join_policy'],
            (string) $row['state'],
            (int) $row['active_member_count'],
            (int) $row['pending_member_count'],
            self::parse((string) $row['created_at_utc']),
            self::parse((string) $row['updated_at_utc']),
        );
    }

    /** @param array<string,mixed> $row */
    private function hydrateMember(array $row): CommunityGroupMember
    {
        return new CommunityGroupMember(
            EntityId::fromString((string) $row['group_id']),
            UserId::fromStored((string) $row['user_id']),
            (string) $row['role_key'],
            (string) $row['state'],
            $row['acted_by_user_id'] === null ? null : UserId::fromStored((string) $row['acted_by_user_id']),
            self::parse((string) $row['created_at_utc']),
            self::parse((string) $row['updated_at_utc']),
        );
    }

    private static function pagination(int $limit, int $offset): void
    {
        if ($limit < 1 || $limit > 100 || $offset < 0 || $offset > 100000) {
            throw new InvalidArgumentException('Community group pagination is invalid.');
        }
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function parse(string $value): DateTimeImmutable
    {
        foreach (['!Y-m-d H:i:s.u','!Y-m-d H:i:s'] as $format) {
            $time = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));
            if ($time instanceof DateTimeImmutable) {
                return $time;
            }
        }
        throw new RuntimeException('Stored community group timestamp is invalid.');
    }
}
