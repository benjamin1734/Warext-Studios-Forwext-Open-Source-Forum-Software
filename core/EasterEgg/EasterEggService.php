<?php

declare(strict_types=1);

namespace Forwext\Core\EasterEgg;

use DateTimeImmutable;
use Forwext\Core\Audit\AuditAction;
use Forwext\Core\Audit\AuditEvent;
use Forwext\Core\Audit\AuditRecorder;
use Forwext\Core\Audit\AuditRequestId;
use Forwext\Core\Audit\AuditScope;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class EasterEggService
{
    public function __construct(
        private TransactionalQueryExecutor $database,
        private EasterEggRepository $repository,
        private PermissionAuthorizer $authorizer,
        private AuditRecorder $audit,
    ) {
    }

    /** @return array{enabled:bool,definitions:list<EasterEggDefinition>} */
    public function manageList(EntityId $actor): array
    {
        $this->requireManage($actor);
        return ['enabled'=>$this->repository->globalEnabled(),'definitions'=>$this->repository->all()];
    }

    public function definition(EntityId $actor, EntityId $id): ?EasterEggDefinition
    {
        $this->requireManage($actor);
        return $this->repository->find($id);
    }

    /** @return list<EasterEggGroupOption> */
    public function availableGroups(EntityId $actor): array
    {
        $this->requireManage($actor);
        return $this->repository->availableGroups();
    }

    /** @return list<EntityId> */
    public function groups(EntityId $actor, EntityId $id): array
    {
        $this->requireManage($actor);
        return $this->repository->groupIds($id);
    }

    public function setGlobalEnabled(
        EntityId $actor,
        bool $enabled,
        DateTimeImmutable $at,
        ?AuditRequestId $requestId = null,
    ): void {
        $this->requireManage($actor);
        $before = $this->repository->globalEnabled();
        $event = new AuditEvent(
            AuditEvent::generateId(),
            AuditScope::Administration,
            $actor,
            AuditAction::fromString('easteregg.global_toggle'),
            'easteregg.setting',
            'global',
            null,
            'easteregg.global_toggle',
            $requestId ?? AuditRequestId::generate(),
            ['enabled'=>$before],
            ['enabled'=>$enabled],
            $at,
        );
        $this->audit->mutate($event, function () use ($enabled, $at): void {
            $this->repository->setGlobalEnabled($enabled, $at);
        });
    }

    /** @param list<EntityId> $groupIds */
    public function save(
        EntityId $actor,
        EasterEggDefinition $definition,
        array $groupIds,
        DateTimeImmutable $at,
        ?AuditRequestId $requestId = null,
    ): void {
        $this->requireManage($actor);
        $existing = $this->repository->find($definition->easterEggId);
        $event = new AuditEvent(
            AuditEvent::generateId(),
            AuditScope::Administration,
            $actor,
            AuditAction::fromString($existing === null ? 'easteregg.create' : 'easteregg.update'),
            'easteregg.definition',
            $definition->easterEggId->value(),
            null,
            $existing === null ? 'easteregg.create' : 'easteregg.update',
            $requestId ?? AuditRequestId::generate(),
            $existing === null ? [] : self::snapshot($existing, count($this->repository->groupIds($definition->easterEggId))),
            self::snapshot($definition, count($groupIds)),
            $at,
        );

        $this->database->transaction(function () use ($event, $definition, $groupIds): void {
            $this->repository->save($definition);
            $this->repository->replaceGroups($definition->easterEggId, $groupIds);
            $this->audit->append($event);
        });
    }

    /** @return list<EasterEggDefinition> */
    public function activeFor(EasterEggRuntimeContext $context): array
    {
        if (!$this->repository->globalEnabled()) {
            return [];
        }

        $visible = [];
        $viewerGroups = array_fill_keys(
            array_map(static fn (EntityId $id): string => $id->value(), $context->groupIds),
            true,
        );

        foreach ($this->repository->activeAt($context->at, 50) as $definition) {
            if (!$definition->activeAt($context->at)
                || !$definition->matchesRoute($context->routeName, $context->path)
                || !$definition->triggerSatisfied($context->queryToken)
            ) {
                continue;
            }

            $requiredGroups = $this->repository->groupIds($definition->easterEggId);
            if ($requiredGroups !== []) {
                $match = false;
                foreach ($requiredGroups as $groupId) {
                    if (isset($viewerGroups[$groupId->value()])) {
                        $match = true;
                        break;
                    }
                }
                if (!$match) {
                    continue;
                }
            }

            $visible[] = $definition;
            if (count($visible) >= 3) {
                break;
            }
        }

        return $visible;
    }

    private function requireManage(EntityId $actor): void
    {
        $decision = $this->authorizer->resolve($actor, PermissionKey::fromString('easteregg.manage'));
        if (!$decision->isAllowed()) {
            throw new PermissionDeniedException($decision);
        }
    }

    /** @return array<string,scalar|null> */
    private static function snapshot(EasterEggDefinition $definition, int $groupCount): array
    {
        return [
            'key'=>$definition->key,
            'enabled'=>$definition->enabled,
            'priority'=>$definition->priority,
            'trigger_type'=>$definition->triggerType->value,
            'route_name'=>$definition->routeName,
            'path_pattern'=>$definition->pathPattern,
            'starts_at'=>$definition->startsAt?->format(DATE_ATOM),
            'ends_at'=>$definition->endsAt?->format(DATE_ATOM),
            'animation'=>$definition->animation->value,
            'has_badge'=>$definition->badgeLabel !== null,
            'group_count'=>$groupCount,
        ];
    }
}
