<?php

declare(strict_types=1);

namespace Forwext\Core\Module\FirstParty;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Audit\AuditAction;
use Forwext\Core\Audit\AuditEvent;
use Forwext\Core\Audit\AuditRecorder;
use Forwext\Core\Audit\AuditRequestId;
use Forwext\Core\Audit\AuditScope;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\DatabaseConnection;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class FirstPartyModuleService
{
    private const ACP_PERMISSION = 'acp.access';
    private const MODULE_PERMISSION = 'module.manage';

    public function __construct(
        private DatabaseConnection $database,
        private FirstPartyModuleRegistry $registry,
        private FirstPartyModuleRepository $repository,
        private FirstPartyModuleDataPurger $purger,
        private PermissionAuthorizer $authorizer,
        private AuditRecorder $audit,
    ) {
    }

    public function managementSnapshot(
        EntityId $actor,
        ?string $selectedModuleKey = null,
        FirstPartyModuleScope $selectedScope = FirstPartyModuleScope::Global,
        ?string $selectedScopeId = null,
    ): array {
        $this->requireManage($actor);
        $records = $this->records();
        $selected = $selectedModuleKey === null ? null : $this->registry->require($selectedModuleKey);
        $settings = [];
        $scopeTargets = [];
        $resolvedScopeId = null;
        $pendingStorage = 0;

        if ($selected !== null) {
            $supportsScope = false;
            foreach ($selected->settings as $setting) {
                if ($setting->supports($selectedScope)) {
                    $supportsScope = true;
                    break;
                }
            }
            if (!$supportsScope && $selected->settings !== []) {
                throw new InvalidArgumentException('Selected module does not support this settings scope.');
            }
            $scopeTargets = $this->scopeTargets($selectedScope);
            if (!$selectedScope->needsTarget()) {
                $resolvedScopeId = 'global';
                $settings = $this->repository->settings(
                    $selected->key,
                    FirstPartyModuleScope::Global,
                    'global',
                );
            } elseif ($selectedScopeId !== null && $selectedScopeId !== '') {
                $resolvedScopeId = $this->scopeId($selectedScope, $selectedScopeId);
                $settings = $this->repository->settings(
                    $selected->key,
                    $selectedScope,
                    $resolvedScopeId,
                );
            }
            $pendingStorage = $this->repository->pendingStoragePathCount($selected->key);
        }

        return [
            'definitions'=>$this->registry->all(),
            'records'=>$records,
            'selected'=>$selected,
            'selected_record'=>$selected === null ? null : $records[$selected->key],
            'selected_scope'=>$selectedScope,
            'selected_scope_id'=>$resolvedScopeId,
            'settings'=>$settings,
            'scope_targets'=>$scopeTargets,
            'pending_storage_count'=>$pendingStorage,
            'dependents'=>$selected === null ? [] : $this->registry->dependentsOf($selected->key),
            'conflicts'=>$selected === null ? [] : $this->registry->conflictsOf($selected->key),
        ];
    }

    public function isEnabled(string $moduleKey): bool
    {
        $this->registry->require($moduleKey);

        return $this->repository->state($moduleKey)->state === FirstPartyModuleState::Enabled;
    }

    public function enable(
        EntityId $actor,
        string $moduleKey,
        AuditRequestId $requestId,
        DateTimeImmutable $at,
    ): void {
        $this->requireManage($actor);
        $definition = $this->registry->require($moduleKey);
        $record = $this->repository->state($moduleKey);
        if ($record->state !== FirstPartyModuleState::Disabled) {
            throw new InvalidArgumentException('Only disabled modules can be enabled.');
        }

        foreach ($definition->dependencies as $dependency) {
            if ($this->repository->state($dependency)->state !== FirstPartyModuleState::Enabled) {
                throw new InvalidArgumentException('Module dependency must be enabled first: ' . $dependency);
            }
        }
        foreach ($this->registry->conflictsOf($moduleKey) as $conflict) {
            if ($this->repository->state($conflict->key)->state === FirstPartyModuleState::Enabled) {
                throw new InvalidArgumentException('Conflicting module is enabled: ' . $conflict->key);
            }
        }

        $this->saveLifecycleState(
            $actor,
            $definition,
            $record,
            FirstPartyModuleState::Enabled,
            $record->dataState,
            'module.enable',
            $requestId,
            $at,
        );
    }

    public function disable(
        EntityId $actor,
        string $moduleKey,
        AuditRequestId $requestId,
        DateTimeImmutable $at,
    ): void {
        $this->requireManage($actor);
        $definition = $this->registry->require($moduleKey);
        $record = $this->repository->state($moduleKey);
        if ($record->state !== FirstPartyModuleState::Enabled) {
            throw new InvalidArgumentException('Only enabled modules can be disabled.');
        }

        foreach ($this->registry->dependentsOf($moduleKey) as $dependent) {
            if ($this->repository->state($dependent->key)->state === FirstPartyModuleState::Enabled) {
                throw new InvalidArgumentException('Enabled dependent module must be disabled first: ' . $dependent->key);
            }
        }

        $this->saveLifecycleState(
            $actor,
            $definition,
            $record,
            FirstPartyModuleState::Disabled,
            $record->dataState,
            'module.disable',
            $requestId,
            $at,
        );
    }

    public function install(
        EntityId $actor,
        string $moduleKey,
        AuditRequestId $requestId,
        DateTimeImmutable $at,
    ): void {
        $this->requireManage($actor);
        $definition = $this->registry->require($moduleKey);
        $record = $this->repository->state($moduleKey);
        if ($record->state !== FirstPartyModuleState::Uninstalled) {
            throw new InvalidArgumentException('Only uninstalled modules can be installed.');
        }
        if ($record->dataState === FirstPartyModuleDataState::PurgePending) {
            throw new InvalidArgumentException('Pending storage purge must finish before reinstall.');
        }
        foreach ($definition->dependencies as $dependency) {
            if ($this->repository->state($dependency)->state === FirstPartyModuleState::Uninstalled) {
                throw new InvalidArgumentException('Module dependency must be installed first: ' . $dependency);
            }
        }

        $this->saveLifecycleState(
            $actor,
            $definition,
            $record,
            FirstPartyModuleState::Disabled,
            FirstPartyModuleDataState::Retained,
            'module.install',
            $requestId,
            $at,
        );
    }

    public function uninstall(
        EntityId $actor,
        string $moduleKey,
        bool $deleteData,
        AuditRequestId $requestId,
        DateTimeImmutable $at,
    ): void {
        $this->requireManage($actor);
        $definition = $this->registry->require($moduleKey);
        $record = $this->repository->state($moduleKey);
        if ($record->state !== FirstPartyModuleState::Disabled) {
            throw new InvalidArgumentException('Module must be disabled before uninstall.');
        }

        foreach ($this->registry->dependentsOf($moduleKey) as $dependent) {
            $dependentRecord = $this->repository->state($dependent->key);
            if ($dependentRecord->state !== FirstPartyModuleState::Uninstalled) {
                throw new InvalidArgumentException('Dependent module must be uninstalled first: ' . $dependent->key);
            }
            if ($deleteData && $dependentRecord->dataState !== FirstPartyModuleDataState::Purged) {
                throw new InvalidArgumentException(
                    'Dependent module data must be purged before deleting parent module data: ' . $dependent->key,
                );
            }
        }

        if (!$deleteData) {
            $this->saveLifecycleState(
                $actor,
                $definition,
                $record,
                FirstPartyModuleState::Uninstalled,
                FirstPartyModuleDataState::Retained,
                'module.uninstall.keep_data',
                $requestId,
                $at,
            );
            return;
        }

        $pending = $this->database->transaction(function () use (
            $definition,
            $record,
            $actor,
            $requestId,
            $at,
        ): int {
            $pending = $this->purger->purgeDatabaseAndQueueStorage($definition, $at);
            $afterDataState = $pending === 0
                ? FirstPartyModuleDataState::Purged
                : FirstPartyModuleDataState::PurgePending;
            $this->repository->saveState(
                $definition->key,
                FirstPartyModuleState::Uninstalled,
                $afterDataState,
                $actor,
                $at,
            );
            $this->audit->append($this->event(
                $actor,
                'module.uninstall.delete_data',
                $definition->key,
                self::snapshot($record),
                [
                    'state'=>FirstPartyModuleState::Uninstalled->value,
                    'data_state'=>$afterDataState->value,
                    'pending_storage'=>$pending,
                ],
                $requestId,
                $at,
            ));

            return $pending;
        });

        if ($pending > 0) {
            $this->retryPurge($actor, $moduleKey, $requestId, $at);
        }
    }

    public function retryPurge(
        EntityId $actor,
        string $moduleKey,
        AuditRequestId $requestId,
        DateTimeImmutable $at,
    ): int {
        $this->requireManage($actor);
        $definition = $this->registry->require($moduleKey);
        $before = $this->repository->state($moduleKey);
        if ($before->state !== FirstPartyModuleState::Uninstalled
            || $before->dataState !== FirstPartyModuleDataState::PurgePending
        ) {
            throw new InvalidArgumentException('Module has no pending purge.');
        }

        $beforePending = $this->repository->pendingStoragePathCount($moduleKey);
        $remaining = $this->purger->processStorageQueue($moduleKey, $at);
        $afterDataState = $remaining === 0
            ? FirstPartyModuleDataState::Purged
            : FirstPartyModuleDataState::PurgePending;

        $event = $this->event(
            $actor,
            'module.purge.retry',
            $definition->key,
            self::snapshot($before) + ['pending_storage'=>$beforePending],
            [
                'state'=>FirstPartyModuleState::Uninstalled->value,
                'data_state'=>$afterDataState->value,
                'pending_storage'=>$remaining,
            ],
            $requestId,
            $at,
        );
        $this->audit->mutate($event, function () use ($moduleKey, $afterDataState, $actor, $at): void {
            $this->repository->saveState(
                $moduleKey,
                FirstPartyModuleState::Uninstalled,
                $afterDataState,
                $actor,
                $at,
            );
        });

        return $remaining;
    }

    public function saveSetting(
        EntityId $actor,
        string $moduleKey,
        string $settingKey,
        FirstPartyModuleScope $scope,
        ?string $scopeId,
        mixed $value,
        AuditRequestId $requestId,
        DateTimeImmutable $at,
    ): void {
        $this->requireManage($actor);
        $definition = $this->registry->require($moduleKey);
        if ($this->repository->state($moduleKey)->state === FirstPartyModuleState::Uninstalled) {
            throw new InvalidArgumentException('Cannot edit settings for an uninstalled module.');
        }
        $setting = $definition->setting($settingKey)
            ?? throw new InvalidArgumentException('Unknown module setting.');
        if (!$setting->supports($scope)) {
            throw new InvalidArgumentException('Module setting does not support this scope.');
        }

        $resolvedScopeId = $this->scopeId($scope, $scopeId);
        $normalized = $setting->normalize($value);
        $before = $this->repository->settings($moduleKey, $scope, $resolvedScopeId);
        $event = $this->event(
            $actor,
            'module.setting.save',
            $moduleKey,
            [
                'scope'=>$scope->value,
                'scope_id'=>$resolvedScopeId,
                'setting'=>$settingKey,
                'value'=>$before[$settingKey] ?? $setting->defaultValue,
            ],
            [
                'scope'=>$scope->value,
                'scope_id'=>$resolvedScopeId,
                'setting'=>$settingKey,
                'value'=>$normalized,
            ],
            $requestId,
            $at,
        );
        $this->audit->mutate($event, function () use (
            $moduleKey,
            $scope,
            $resolvedScopeId,
            $settingKey,
            $normalized,
            $actor,
            $at,
        ): void {
            $this->repository->saveSetting(
                $moduleKey,
                $scope,
                $resolvedScopeId,
                $settingKey,
                $normalized,
                $actor,
                $at,
            );
        });
    }

    public function resetSetting(
        EntityId $actor,
        string $moduleKey,
        string $settingKey,
        FirstPartyModuleScope $scope,
        ?string $scopeId,
        AuditRequestId $requestId,
        DateTimeImmutable $at,
    ): void {
        $this->requireManage($actor);
        $definition = $this->registry->require($moduleKey);
        if ($this->repository->state($moduleKey)->state === FirstPartyModuleState::Uninstalled) {
            throw new InvalidArgumentException('Cannot edit settings for an uninstalled module.');
        }
        $setting = $definition->setting($settingKey)
            ?? throw new InvalidArgumentException('Unknown module setting.');
        if (!$setting->supports($scope)) {
            throw new InvalidArgumentException('Module setting does not support this scope.');
        }

        $resolvedScopeId = $this->scopeId($scope, $scopeId);
        $before = $this->repository->settings($moduleKey, $scope, $resolvedScopeId);
        if (!array_key_exists($settingKey, $before)) {
            return;
        }

        $event = $this->event(
            $actor,
            'module.setting.reset',
            $moduleKey,
            [
                'scope'=>$scope->value,
                'scope_id'=>$resolvedScopeId,
                'setting'=>$settingKey,
                'value'=>$before[$settingKey],
            ],
            [
                'scope'=>$scope->value,
                'scope_id'=>$resolvedScopeId,
                'setting'=>$settingKey,
                'override_removed'=>true,
            ],
            $requestId,
            $at,
        );
        $this->audit->mutate($event, function () use (
            $moduleKey,
            $scope,
            $resolvedScopeId,
            $settingKey,
        ): void {
            $this->repository->deleteSetting(
                $moduleKey,
                $scope,
                $resolvedScopeId,
                $settingKey,
            );
        });
    }

    public function effectiveSetting(
        string $moduleKey,
        string $settingKey,
        ?EntityId $forumId = null,
        ?EntityId $groupId = null,
        ?EntityId $threadId = null,
        ?EntityId $postId = null,
    ): bool|int|string {
        $definition = $this->registry->require($moduleKey);
        $setting = $definition->setting($settingKey)
            ?? throw new InvalidArgumentException('Unknown module setting.');

        $candidates = [
            [FirstPartyModuleScope::Post, $postId],
            [FirstPartyModuleScope::Thread, $threadId],
            [FirstPartyModuleScope::Forum, $forumId],
            [FirstPartyModuleScope::Group, $groupId],
        ];
        foreach ($candidates as [$scope, $id]) {
            if (!$id instanceof EntityId || !$setting->supports($scope)) {
                continue;
            }
            $values = $this->repository->settings($moduleKey, $scope, $id->value());
            if (array_key_exists($settingKey, $values)) {
                return $values[$settingKey];
            }
        }

        if ($setting->supports(FirstPartyModuleScope::Global)) {
            $global = $this->repository->settings($moduleKey, FirstPartyModuleScope::Global, 'global');
            if (array_key_exists($settingKey, $global)) {
                return $global[$settingKey];
            }
        }

        return $setting->defaultValue;
    }

    private function records(): array
    {
        $stored = $this->repository->states();
        $result = [];
        foreach ($this->registry->all() as $definition) {
            $result[$definition->key] = $stored[$definition->key]
                ?? $this->repository->state($definition->key);
        }

        return $result;
    }

    private function saveLifecycleState(
        EntityId $actor,
        FirstPartyModuleDefinition $definition,
        FirstPartyModuleRecord $before,
        FirstPartyModuleState $state,
        FirstPartyModuleDataState $dataState,
        string $action,
        AuditRequestId $requestId,
        DateTimeImmutable $at,
    ): void {
        $this->audit->mutate(
            $this->event(
                $actor,
                $action,
                $definition->key,
                self::snapshot($before),
                ['state'=>$state->value,'data_state'=>$dataState->value],
                $requestId,
                $at,
            ),
            fn (): mixed => $this->repository->saveState(
                $definition->key,
                $state,
                $dataState,
                $actor,
                $at,
            ),
        );
    }

    private function scopeId(FirstPartyModuleScope $scope, ?string $scopeId): string
    {
        if (!$scope->needsTarget()) {
            return 'global';
        }
        if ($scopeId === null || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/D', $scopeId) !== 1) {
            throw new InvalidArgumentException('Module setting scope target is invalid.');
        }

        $exists = match ($scope) {
            FirstPartyModuleScope::Global => 1,
            FirstPartyModuleScope::Forum => $this->database->fetchValue(new CompiledQuery(
                "SELECT COUNT(*) FROM forwext_nodes WHERE node_id=:id AND node_type='forum'",
                ['id'=>$scopeId],
            )),
            FirstPartyModuleScope::Group => $this->database->fetchValue(new CompiledQuery(
                'SELECT COUNT(*) FROM forwext_user_groups WHERE group_id=:id',
                ['id'=>$scopeId],
            )),
            FirstPartyModuleScope::Thread => $this->database->fetchValue(new CompiledQuery(
                'SELECT COUNT(*) FROM forwext_threads WHERE thread_id=:id',
                ['id'=>$scopeId],
            )),
            FirstPartyModuleScope::Post => $this->database->fetchValue(new CompiledQuery(
                'SELECT COUNT(*) FROM forwext_posts WHERE post_id=:id',
                ['id'=>$scopeId],
            )),
        };
        if ((int) $exists !== 1) {
            throw new InvalidArgumentException('Module setting scope target was not found.');
        }

        return $scopeId;
    }

    /** @return list<array{id:string,label:string}> */
    private function scopeTargets(FirstPartyModuleScope $scope): array
    {
        $query = match ($scope) {
            FirstPartyModuleScope::Global => null,
            FirstPartyModuleScope::Forum => new CompiledQuery(
                "SELECT node_id AS id,title AS label FROM forwext_nodes WHERE node_type='forum' "
                . 'ORDER BY title,node_id LIMIT 200',
            ),
            FirstPartyModuleScope::Group => new CompiledQuery(
                'SELECT group_id AS id,name AS label FROM forwext_user_groups '
                . 'ORDER BY sort_order,name,group_id LIMIT 200',
            ),
            FirstPartyModuleScope::Thread,
            FirstPartyModuleScope::Post => null,
        };
        if (!$query instanceof CompiledQuery) {
            return [];
        }

        $result = [];
        foreach ($this->database->fetchAll($query) as $row) {
            $id = $row['id'] ?? null;
            $label = $row['label'] ?? null;
            if (is_string($id) && $id !== '' && is_string($label) && $label !== '') {
                $result[] = ['id'=>$id,'label'=>$label];
            }
        }

        return $result;
    }

    private function requireManage(EntityId $actor): void
    {
        foreach ([self::ACP_PERMISSION, self::MODULE_PERMISSION] as $permission) {
            $decision = $this->authorizer->resolve($actor, PermissionKey::fromString($permission));
            if (!$decision->isAllowed()) {
                throw new PermissionDeniedException($decision);
            }
        }
    }

    private function event(
        EntityId $actor,
        string $action,
        string $moduleKey,
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
            'first_party_module',
            $moduleKey,
            null,
            $action,
            $requestId,
            $before,
            $after,
            $at->setTimezone(new DateTimeZone('UTC')),
        );
    }

    private static function snapshot(FirstPartyModuleRecord $record): array
    {
        return [
            'state'=>$record->state->value,
            'data_state'=>$record->dataState->value,
        ];
    }
}
