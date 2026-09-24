<?php

declare(strict_types=1);

namespace Forwext\Core\Admin\Community;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Audit\AuditAction;
use Forwext\Core\Audit\AuditEvent;
use Forwext\Core\Audit\AuditRecorder;
use Forwext\Core\Audit\AuditRequestId;
use Forwext\Core\Audit\AuditScope;
use Forwext\Core\Audit\CoreAuditService;
use Forwext\Core\Audit\DatabaseAuditEventStore;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\DatabaseConnection;
use Forwext\Core\Domain\Access\AccessIdentifier;
use Forwext\Core\Domain\Access\Appearance\RoleAppearance;
use Forwext\Core\Domain\Access\Appearance\RoleAppearanceRepository;
use Forwext\Core\Domain\Access\Permission\Analyzer\PermissionAnalysis;
use Forwext\Core\Domain\Access\Permission\Analyzer\PermissionAnalyzer;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Access\Role;
use Forwext\Core\Domain\Access\RoleKind;
use Forwext\Core\Domain\Access\UserAccessAssignmentProvider;
use Forwext\Core\Domain\Access\UserGroup;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Domain\User\UserStatus;
use Forwext\Core\Forum\Node\ForumNode;
use Forwext\Core\Forum\Node\ForumNodeRepository;
use InvalidArgumentException;

final readonly class AdminCommunityService
{
    public const MANAGE_PERMISSION = 'acp.manage';

    public function __construct(
        private DatabaseConnection $database,
        private UserRepository $users,
        private UserAccessAssignmentProvider $assignments,
        private PermissionAnalyzer $permissionAnalyzer,
        private RoleAppearanceRepository $roleAppearances,
        private ForumNodeRepository $nodes,
        private PermissionAuthorizer $authorizer,
        private AuditRecorder $audit,
    ) {
    }

    /**
     * @return array{
     *   users:list<array<string,mixed>>,
     *   selected:?\Forwext\Core\Domain\User\User,
     *   selected_history:list<\Forwext\Core\Domain\User\UserHistoryEntry>,
     *   access:array{primary:?string,secondary:list<string>,roles:list<string>}
     * }
     */
    public function usersSnapshot(EntityId $actor, string $search, ?EntityId $selectedUserId): array
    {
        $this->requireManage($actor);
        $search = trim($search);
        if (strlen($search) > 80 || preg_match('//u', $search) !== 1) {
            throw new InvalidArgumentException('ACP user search is invalid.');
        }

        $parameters = [];
        $where = '';
        if ($search !== '') {
            $where = ' WHERE username LIKE :search OR email LIKE :search';
            $parameters['search'] = '%' . $search . '%';
        }

        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT user_id,username,email,status,locale,timezone,created_at_utc,updated_at_utc '
            . 'FROM forwext_users' . $where . ' ORDER BY updated_at_utc DESC,user_id DESC LIMIT 100',
            $parameters,
        ));

        $selected = $selectedUserId === null ? null : $this->users->find($selectedUserId);
        if ($selectedUserId !== null && $selected === null) {
            throw new InvalidArgumentException('ACP user was not found.');
        }

        return [
            'users'=>$rows,
            'selected'=>$selected,
            'selected_history'=>$selected === null ? [] : $this->users->history($selected->id(), 50),
            'access'=>$selected === null ? ['primary'=>null,'secondary'=>[],'roles'=>[]]
                : $this->directAccess($selected->id()),
        ];
    }

    /**
     * @return array{
     *   groups:list<array<string,mixed>>,
     *   roles:list<array<string,mixed>>,
     *   permissions:list<array<string,mixed>>,
     *   nodes:list<array<string,mixed>>,
     *   users:list<array<string,mixed>>,
     *   selected_role:?array<string,mixed>,
     *   selected_appearance:?RoleAppearance,
     *   analysis:?PermissionAnalysis
     * }
     */
    public function accessSnapshot(
        EntityId $actor,
        ?string $selectedRoleId,
        ?EntityId $analyzeUserId,
        string $permissionKey,
        ?EntityId $nodeId,
    ): array {
        $this->requireManage($actor);

        $groups = $this->database->fetchAll(new CompiledQuery(
            'SELECT g.group_id,g.group_key,g.name,g.is_system,g.sort_order,'
            . '(SELECT COUNT(*) FROM forwext_user_primary_groups pg WHERE pg.group_id=g.group_id) AS primary_members,'
            . '(SELECT COUNT(*) FROM forwext_user_secondary_groups sg WHERE sg.group_id=g.group_id) AS secondary_members '
            . 'FROM forwext_user_groups g ORDER BY g.sort_order,g.name,g.group_id',
        ));
        $roles = $this->database->fetchAll(new CompiledQuery(
            'SELECT r.role_id,r.role_key,r.name,r.kind,r.is_protected,r.priority,'
            . '(SELECT COUNT(*) FROM forwext_user_role_assignments ra WHERE ra.role_id=r.role_id) AS direct_members '
            . 'FROM forwext_roles r ORDER BY r.priority DESC,r.name,r.role_id',
        ));
        $permissions = $this->database->fetchAll(new CompiledQuery(
            'SELECT permission_key,value_type,description FROM forwext_permissions ORDER BY permission_key LIMIT 1000',
        ));
        $nodes = $this->database->fetchAll(new CompiledQuery(
            'SELECT node_id,title,node_type FROM forwext_nodes ORDER BY sort_order,title,node_id',
        ));
        $users = $this->database->fetchAll(new CompiledQuery(
            'SELECT user_id,username,status FROM forwext_users ORDER BY updated_at_utc DESC,user_id DESC LIMIT 200',
        ));

        $selectedRole = null;
        $selectedAppearance = null;
        if ($selectedRoleId !== null && $selectedRoleId !== '') {
            self::assertStoredIdentifier($selectedRoleId);
            foreach ($roles as $role) {
                if ((string) $role['role_id'] === $selectedRoleId) {
                    $selectedRole = $role;
                    $selectedAppearance = $this->roleAppearances->find(EntityId::fromString($selectedRoleId));
                    break;
                }
            }
            if ($selectedRole === null) {
                throw new InvalidArgumentException('ACP role was not found.');
            }
        }

        $analysis = null;
        $permissionKey = trim($permissionKey);
        if ($permissionKey !== '') {
            if ($analyzeUserId === null) {
                throw new InvalidArgumentException('Permission analysis requires a user.');
            }
            if ($this->users->find($analyzeUserId) === null) {
                throw new InvalidArgumentException('Permission analysis user was not found.');
            }
            if ($nodeId !== null && $this->nodes->find($nodeId) === null) {
                throw new InvalidArgumentException('Permission analysis node was not found.');
            }
            $assignment = $this->assignments->find($analyzeUserId);
            if ($assignment === null) {
                throw new InvalidArgumentException('Permission analysis assignment was not found.');
            }
            $analysis = $this->permissionAnalyzer->analyze(
                PermissionKey::fromString($permissionKey),
                $assignment,
                $nodeId,
            );
        }

        return [
            'groups'=>$groups,
            'roles'=>$roles,
            'permissions'=>$permissions,
            'nodes'=>$nodes,
            'users'=>$users,
            'selected_role'=>$selectedRole,
            'selected_appearance'=>$selectedAppearance,
            'analysis'=>$analysis,
        ];
    }

    /**
     * @return array{
     *   nodes:list<ForumNode>,
     *   selected:?ForumNode,
     *   stats:array<string,array{threads:int,posts:int}>,
     *   totals:array{threads:int,posts:int,thread_pending:int,post_pending:int,deleted_threads:int,deleted_posts:int}
     * }
     */
    public function forumsSnapshot(EntityId $actor, ?EntityId $selectedNodeId): array
    {
        $this->requireManage($actor);
        $nodes = $this->nodes->all();
        $selected = $selectedNodeId === null ? null : $this->nodes->find($selectedNodeId);
        if ($selectedNodeId !== null && $selected === null) {
            throw new InvalidArgumentException('ACP forum node was not found.');
        }

        $stats = [];
        foreach ($this->database->fetchAll(new CompiledQuery(
            'SELECT t.forum_node_id,COUNT(DISTINCT t.thread_id) AS threads,COUNT(p.post_id) AS posts '
            . 'FROM forwext_threads t LEFT JOIN forwext_posts p ON p.thread_id=t.thread_id '
            . 'GROUP BY t.forum_node_id',
        )) as $row) {
            $stats[(string) $row['forum_node_id']] = [
                'threads'=>(int) $row['threads'],
                'posts'=>(int) $row['posts'],
            ];
        }

        return [
            'nodes'=>$nodes,
            'selected'=>$selected,
            'stats'=>$stats,
            'totals'=>$this->contentTotals(),
        ];
    }

    /**
     * @return array{
     *   totals:array{threads:int,posts:int,thread_pending:int,post_pending:int,deleted_threads:int,deleted_posts:int},
     *   capabilities:array<string,bool>,
     *   moderation:array<string,int>,
     *   audit:list<AuditEvent>
     * }
     */
    public function operationsSnapshot(EntityId $actor): array
    {
        $this->requireManage($actor);
        $capabilities = [
            'moderation'=>$this->allows($actor, 'moderation.access'),
            'reports'=>$this->allows($actor, 'moderation.access'),
            'discipline'=>$this->allows($actor, 'moderation.discipline.view'),
            'ban'=>$this->allows($actor, 'moderation.ban.manage'),
            'audit'=>$this->allows($actor, 'audit.view'),
            'content_manager'=>$this->allows($actor, 'content.manage'),
        ];

        $moderation = [
            'reports'=>0,
            'tasks'=>0,
            'active_bans'=>0,
            'active_warnings'=>0,
        ];
        if ($capabilities['moderation']) {
            $moderation['reports'] = $this->count(
                "SELECT COUNT(*) FROM forwext_report_groups WHERE status IN ('open','in_review')",
            );
            $moderation['tasks'] = $this->count(
                "SELECT COUNT(*) FROM forwext_moderation_tasks WHERE status IN ('open','in_progress')",
            );
        }
        if ($capabilities['discipline']) {
            $moderation['active_bans'] = $this->count(
                "SELECT COUNT(*) FROM forwext_discipline_actions WHERE action_type IN ('ban','suspension') "
                . "AND revoked_at_utc IS NULL AND starts_at_utc<=UTC_TIMESTAMP(6) "
                . "AND (expires_at_utc IS NULL OR expires_at_utc>UTC_TIMESTAMP(6))",
            );
            $moderation['active_warnings'] = $this->count(
                "SELECT COUNT(*) FROM forwext_discipline_actions WHERE action_type='warning' "
                . "AND revoked_at_utc IS NULL AND starts_at_utc<=UTC_TIMESTAMP(6) "
                . "AND (expires_at_utc IS NULL OR expires_at_utc>UTC_TIMESTAMP(6))",
            );
        }

        $audit = [];
        if ($capabilities['audit']) {
            $audit = (new CoreAuditService(
                new DatabaseAuditEventStore($this->database),
                new PermissionGate($this->authorizer, $actor),
            ))->recent(50);
        }

        return [
            'totals'=>$this->contentTotals(),
            'capabilities'=>$capabilities,
            'moderation'=>$moderation,
            'audit'=>$audit,
        ];
    }

    /**
     * @param list<string> $secondaryGroupIds
     * @param list<string> $roleIds
     */
    public function replaceUserAccess(
        EntityId $actor,
        EntityId $userId,
        ?string $primaryGroupId,
        array $secondaryGroupIds,
        array $roleIds,
        AuditRequestId $requestId,
        DateTimeImmutable $at,
    ): void {
        $this->requireManage($actor);
        if ($actor->equals($userId)) {
            throw new InvalidArgumentException('Administrators cannot rewrite their own access assignment from this surface.');
        }
        if ($this->users->find($userId) === null) {
            throw new InvalidArgumentException('ACP access target user was not found.');
        }

        $primaryGroupId = $primaryGroupId === null || $primaryGroupId === '' ? null : $primaryGroupId;
        if ($primaryGroupId !== null) {
            $this->requireExisting('forwext_user_groups', 'group_id', $primaryGroupId);
        }
        $secondaryGroupIds = self::storedIdentifiers($secondaryGroupIds, 100);
        $roleIds = self::storedIdentifiers($roleIds, 100);
        foreach ($secondaryGroupIds as $groupId) {
            $this->requireExisting('forwext_user_groups', 'group_id', $groupId);
            if ($primaryGroupId !== null && hash_equals($primaryGroupId, $groupId)) {
                throw new InvalidArgumentException('Primary group cannot also be secondary.');
            }
        }
        foreach ($roleIds as $roleId) {
            $this->requireExisting('forwext_roles', 'role_id', $roleId);
        }
        if ($primaryGroupId === null && $secondaryGroupIds !== []) {
            throw new InvalidArgumentException('Secondary groups require a primary group.');
        }

        $before = $this->directAccess($userId);
        $after = ['primary'=>$primaryGroupId,'secondary'=>$secondaryGroupIds,'roles'=>$roleIds];
        $event = $this->event(
            $actor,
            'acp.user_access.replace',
            'user.access',
            $userId->value(),
            $before,
            $after,
            $requestId,
            $at,
        );

        $this->audit->mutate($event, function () use ($userId, $primaryGroupId, $secondaryGroupIds, $roleIds): void {
            $this->database->execute(new CompiledQuery(
                'DELETE FROM forwext_user_secondary_groups WHERE user_id=:user_id',
                ['user_id'=>$userId->value()],
            ));
            $this->database->execute(new CompiledQuery(
                'DELETE FROM forwext_user_role_assignments WHERE user_id=:user_id',
                ['user_id'=>$userId->value()],
            ));

            if ($primaryGroupId === null) {
                $this->database->execute(new CompiledQuery(
                    'DELETE FROM forwext_user_primary_groups WHERE user_id=:user_id',
                    ['user_id'=>$userId->value()],
                ));
            } else {
                $this->database->execute(new CompiledQuery(
                    'INSERT INTO forwext_user_primary_groups (user_id,group_id,assigned_at_utc) '
                    . 'VALUES (:user_id,:group_id,UTC_TIMESTAMP(6)) '
                    . 'ON DUPLICATE KEY UPDATE group_id=VALUES(group_id),assigned_at_utc=VALUES(assigned_at_utc)',
                    ['user_id'=>$userId->value(),'group_id'=>$primaryGroupId],
                ));
            }

            foreach ($secondaryGroupIds as $groupId) {
                $this->database->execute(new CompiledQuery(
                    'INSERT INTO forwext_user_secondary_groups (user_id,group_id,assigned_at_utc) '
                    . 'VALUES (:user_id,:group_id,UTC_TIMESTAMP(6))',
                    ['user_id'=>$userId->value(),'group_id'=>$groupId],
                ));
            }
            foreach ($roleIds as $roleId) {
                $this->database->execute(new CompiledQuery(
                    'INSERT INTO forwext_user_role_assignments (user_id,role_id,assigned_at_utc) '
                    . 'VALUES (:user_id,:role_id,UTC_TIMESTAMP(6))',
                    ['user_id'=>$userId->value(),'role_id'=>$roleId],
                ));
            }
        });
    }

    public function changeUserStatus(
        EntityId $actor,
        EntityId $userId,
        UserStatus $status,
        string $reason,
        AuditRequestId $requestId,
        DateTimeImmutable $at,
    ): void {
        $this->requireManage($actor);
        if ($actor->equals($userId)) {
            throw new InvalidArgumentException('Administrators cannot change their own account state from this surface.');
        }
        if (in_array($status, [UserStatus::Suspended, UserStatus::Banned], true)) {
            throw new InvalidArgumentException('Suspensions and bans must use the moderation discipline workflow.');
        }

        $user = $this->users->find($userId)
            ?? throw new InvalidArgumentException('ACP user was not found.');
        if ($user->status()->isModerationRestricted()) {
            throw new InvalidArgumentException('Moderation-restricted account state must be changed through Discipline revoke/ban workflow.');
        }
        $reason = trim($reason);
        if ($reason === '' || strlen($reason) > 120) {
            throw new InvalidArgumentException('ACP account-state reason must contain 1-120 bytes.');
        }

        $before = ['status'=>$user->status()->value];
        if (!$user->changeStatus($status, $at, actorId:$actor, reasonCode:'acp.' . self::reasonKey($reason))) {
            return;
        }
        $after = ['status'=>$user->status()->value];

        $this->audit->mutate(
            $this->event(
                $actor,
                'acp.user_status.change',
                'user.account',
                $userId->value(),
                $before,
                $after,
                $requestId,
                $at,
            ),
            fn (): mixed => $this->users->save($user),
        );
    }

    public function saveGroup(
        EntityId $actor,
        ?string $groupId,
        string $key,
        string $name,
        int $sortOrder,
        AuditRequestId $requestId,
        DateTimeImmutable $at,
    ): string {
        $this->requireManage($actor);
        $identifier = AccessIdentifier::fromString($key);
        $groupId = $groupId === null || $groupId === ''
            ? bin2hex(random_bytes(16))
            : self::storedIdentifier($groupId);
        $existing = $this->database->fetchOne(new CompiledQuery(
            'SELECT group_id,group_key,name,is_system,sort_order FROM forwext_user_groups WHERE group_id=:id',
            ['id'=>$groupId],
        ));
        $group = new UserGroup(
            EntityId::fromString($groupId),
            $existing !== null && (bool) $existing['is_system']
                ? AccessIdentifier::fromString((string) $existing['group_key'])
                : $identifier,
            $name,
            $existing !== null && (bool) $existing['is_system'],
            $sortOrder,
        );

        $after = self::groupSnapshot($group);
        $event = $this->event(
            $actor,
            $existing === null ? 'acp.group.create' : 'acp.group.update',
            'access.group',
            $groupId,
            $existing ?? [],
            $after,
            $requestId,
            $at,
        );
        $this->audit->mutate($event, function () use ($group): void {
            $this->database->execute(new CompiledQuery(
                'INSERT INTO forwext_user_groups '
                . '(group_id,group_key,name,is_system,sort_order,created_at_utc,updated_at_utc) '
                . 'VALUES (:id,:key,:name,:system,:sort_order,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) '
                . 'ON DUPLICATE KEY UPDATE group_key=VALUES(group_key),name=VALUES(name),'
                . 'sort_order=VALUES(sort_order),updated_at_utc=VALUES(updated_at_utc)',
                [
                    'id'=>$group->id()->value(),
                    'key'=>$group->key()->value(),
                    'name'=>$group->name(),
                    'system'=>$group->isSystem(),
                    'sort_order'=>$group->sortOrder(),
                ],
            ));
        });

        return $groupId;
    }

    public function saveRole(
        EntityId $actor,
        ?string $roleId,
        string $key,
        string $name,
        RoleKind $kind,
        int $priority,
        AuditRequestId $requestId,
        DateTimeImmutable $at,
    ): string {
        $this->requireManage($actor);
        $identifier = AccessIdentifier::fromString($key);
        $roleId = $roleId === null || $roleId === ''
            ? bin2hex(random_bytes(16))
            : self::storedIdentifier($roleId);
        $existing = $this->database->fetchOne(new CompiledQuery(
            'SELECT role_id,role_key,name,kind,is_protected,priority FROM forwext_roles WHERE role_id=:id',
            ['id'=>$roleId],
        ));

        $protected = $existing !== null && (bool) $existing['is_protected'];
        $effectiveKind = $protected ? RoleKind::from((string) $existing['kind']) : $kind;
        $effectiveKey = $protected
            ? AccessIdentifier::fromString((string) $existing['role_key'])
            : $identifier;
        if ($existing === null && $kind === RoleKind::System) {
            throw new InvalidArgumentException('System roles cannot be created from ACP.');
        }

        $role = new Role(
            EntityId::fromString($roleId),
            $effectiveKey,
            $name,
            $effectiveKind,
            $protected,
            $priority,
        );

        $event = $this->event(
            $actor,
            $existing === null ? 'acp.role.create' : 'acp.role.update',
            'access.role',
            $roleId,
            $existing ?? [],
            self::roleSnapshot($role),
            $requestId,
            $at,
        );
        $this->audit->mutate($event, function () use ($role): void {
            $this->database->execute(new CompiledQuery(
                'INSERT INTO forwext_roles '
                . '(role_id,role_key,name,kind,is_protected,priority,created_at_utc,updated_at_utc) '
                . 'VALUES (:id,:key,:name,:kind,:protected,:priority,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) '
                . 'ON DUPLICATE KEY UPDATE role_key=VALUES(role_key),name=VALUES(name),'
                . 'kind=VALUES(kind),priority=VALUES(priority),updated_at_utc=VALUES(updated_at_utc)',
                [
                    'id'=>$role->id()->value(),
                    'key'=>$role->key()->value(),
                    'name'=>$role->name(),
                    'kind'=>$role->kind()->value,
                    'protected'=>$role->isProtected(),
                    'priority'=>$role->priority(),
                ],
            ));
        });

        return $roleId;
    }

    public function saveRoleAppearance(
        EntityId $actor,
        RoleAppearance $appearance,
        AuditRequestId $requestId,
        DateTimeImmutable $at,
    ): void {
        $this->requireManage($actor);
        $this->requireExisting('forwext_roles', 'role_id', $appearance->roleId()->value());
        $before = $this->roleAppearances->find($appearance->roleId());
        $this->audit->mutate(
            $this->event(
                $actor,
                'acp.role_appearance.save',
                'access.role_appearance',
                $appearance->roleId()->value(),
                $before === null ? [] : self::appearanceSnapshot($before),
                self::appearanceSnapshot($appearance),
                $requestId,
                $at,
            ),
            fn (): mixed => $this->roleAppearances->save($appearance),
        );
    }

    public function saveForumNode(
        EntityId $actor,
        ForumNode $node,
        AuditRequestId $requestId,
        DateTimeImmutable $at,
    ): void {
        $this->requireManage($actor);
        $before = $this->nodes->find($node->id());
        if ($before !== null && $before->type() !== $node->type()) {
            throw new InvalidArgumentException('Existing forum node type cannot be changed from ACP.');
        }

        $this->audit->mutate(
            $this->event(
                $actor,
                $before === null ? 'acp.forum_node.create' : 'acp.forum_node.update',
                'forum.node',
                $node->id()->value(),
                $before === null ? [] : self::nodeSnapshot($before),
                self::nodeSnapshot($node),
                $requestId,
                $at,
            ),
            fn (): mixed => $this->nodes->save($node),
        );
    }

    /** @return array{primary:?string,secondary:list<string>,roles:list<string>} */
    private function directAccess(EntityId $userId): array
    {
        $primary = $this->database->fetchOne(new CompiledQuery(
            'SELECT group_id FROM forwext_user_primary_groups WHERE user_id=:user_id LIMIT 1',
            ['user_id'=>$userId->value()],
        ));
        $secondary = $this->database->fetchAll(new CompiledQuery(
            'SELECT group_id FROM forwext_user_secondary_groups WHERE user_id=:user_id ORDER BY group_id',
            ['user_id'=>$userId->value()],
        ));
        $roles = $this->database->fetchAll(new CompiledQuery(
            'SELECT role_id FROM forwext_user_role_assignments WHERE user_id=:user_id ORDER BY role_id',
            ['user_id'=>$userId->value()],
        ));

        return [
            'primary'=>$primary === null ? null : (string) $primary['group_id'],
            'secondary'=>array_map(static fn (array $row): string => (string) $row['group_id'], $secondary),
            'roles'=>array_map(static fn (array $row): string => (string) $row['role_id'], $roles),
        ];
    }

    /** @return array{threads:int,posts:int,thread_pending:int,post_pending:int,deleted_threads:int,deleted_posts:int} */
    private function contentTotals(): array
    {
        return [
            'threads'=>$this->count('SELECT COUNT(*) FROM forwext_threads'),
            'posts'=>$this->count('SELECT COUNT(*) FROM forwext_posts'),
            'thread_pending'=>$this->count("SELECT COUNT(*) FROM forwext_threads WHERE moderation_state='pending'"),
            'post_pending'=>$this->count("SELECT COUNT(*) FROM forwext_posts WHERE moderation_state='pending'"),
            'deleted_threads'=>$this->count('SELECT COUNT(*) FROM forwext_threads WHERE deleted=1'),
            'deleted_posts'=>$this->count('SELECT COUNT(*) FROM forwext_posts WHERE deleted=1'),
        ];
    }

    private function count(string $sql): int
    {
        return max(0, (int) $this->database->fetchValue(new CompiledQuery($sql)));
    }

    private function requireManage(EntityId $actor): void
    {
        $decision = $this->authorizer->resolve($actor, PermissionKey::fromString(self::MANAGE_PERMISSION));
        if (!$decision->isAllowed()) {
            throw new PermissionDeniedException($decision);
        }
    }

    private function allows(EntityId $actor, string $permission): bool
    {
        return $this->authorizer->allows($actor, PermissionKey::fromString($permission));
    }

    private function requireExisting(string $table, string $column, string $id): void
    {
        if (!in_array($table, ['forwext_user_groups','forwext_roles'], true)
            || !in_array($column, ['group_id','role_id'], true)
        ) {
            throw new InvalidArgumentException('ACP internal lookup is invalid.');
        }
        self::assertStoredIdentifier($id);
        $exists = $this->database->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $column . '=:id',
            ['id'=>$id],
        ));
        if ((int) $exists !== 1) {
            throw new InvalidArgumentException('ACP referenced access object was not found.');
        }
    }

    private function event(
        EntityId $actor,
        string $action,
        string $targetType,
        string $targetId,
        array $before,
        array $after,
        AuditRequestId $requestId,
        DateTimeImmutable $at,
    ): AuditEvent {
        return new AuditEvent(
            AuditEvent::generateId(),
            AuditScope::Administration,
            $actor,
            AuditAction::fromString($action),
            $targetType,
            $targetId,
            null,
            $action,
            $requestId,
            $before,
            $after,
            $at->setTimezone(new DateTimeZone('UTC')),
        );
    }

    /** @return array<string,mixed> */
    private static function groupSnapshot(UserGroup $group): array
    {
        return [
            'key'=>$group->key()->value(),
            'name'=>$group->name(),
            'system'=>$group->isSystem(),
            'sort_order'=>$group->sortOrder(),
        ];
    }

    /** @return array<string,mixed> */
    private static function roleSnapshot(Role $role): array
    {
        return [
            'key'=>$role->key()->value(),
            'name'=>$role->name(),
            'kind'=>$role->kind()->value,
            'protected'=>$role->isProtected(),
            'priority'=>$role->priority(),
        ];
    }

    /** @return array<string,mixed> */
    private static function appearanceSnapshot(RoleAppearance $appearance): array
    {
        return [
            'text_color'=>$appearance->textColor()?->value(),
            'gradient_from'=>$appearance->gradientFrom()?->value(),
            'gradient_to'=>$appearance->gradientTo()?->value(),
            'gradient_angle'=>$appearance->gradientAngle(),
            'icon'=>$appearance->icon()?->value,
            'banner_text'=>$appearance->bannerText(),
            'banner_color'=>$appearance->bannerColor()?->value(),
            'pattern'=>$appearance->pattern()->value,
            'animation'=>$appearance->animation()->value,
            'show_mobile'=>$appearance->showMobile(),
            'show_profile'=>$appearance->showProfile(),
            'show_posts'=>$appearance->showPosts(),
        ];
    }

    /** @return array<string,mixed> */
    private static function nodeSnapshot(ForumNode $node): array
    {
        $settings = $node->forumSettings();
        return [
            'parent_id'=>$node->parentId()?->value(),
            'type'=>$node->type()->value,
            'title'=>$node->title(),
            'slug'=>$node->slug()->value(),
            'description'=>$node->description(),
            'sort_order'=>$node->sortOrder(),
            'visibility'=>$node->visibility()->value,
            'allow_new_threads'=>$settings?->allowNewThreads(),
            'allow_replies'=>$settings?->allowReplies(),
            'require_thread_approval'=>$settings?->requireThreadApproval(),
            'require_post_approval'=>$settings?->requirePostApproval(),
            'default_thread_sort'=>$settings?->defaultThreadSort()->value,
            'threads_per_page'=>$settings?->threadsPerPage(),
            'page_content'=>$node->pageContent(),
            'link_target'=>$node->linkTarget()?->value(),
            'link_new_window'=>$node->linkNewWindow(),
        ];
    }

    /** @param list<string> $values @return list<string> */
    private static function storedIdentifiers(array $values, int $limit): array
    {
        if (count($values) > $limit) {
            throw new InvalidArgumentException('ACP access assignment contains too many values.');
        }
        $result = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new InvalidArgumentException('ACP access assignment value is invalid.');
            }
            $value = self::storedIdentifier($value);
            $result[$value] = $value;
        }

        return array_values($result);
    }

    private static function storedIdentifier(string $value): string
    {
        self::assertStoredIdentifier($value);
        return $value;
    }

    private static function assertStoredIdentifier(string $value): void
    {
        if ($value === '' || strlen($value) > 191
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $value) !== 1
        ) {
            throw new InvalidArgumentException('ACP stored identifier is invalid.');
        }
    }

    private static function reasonKey(string $reason): string
    {
        $normalized = strtolower(preg_replace('/[^a-z0-9]+/i', '.', $reason) ?? '');
        $normalized = trim($normalized, '.');

        return $normalized === '' ? 'account.state' : substr($normalized, 0, 48);
    }
}
