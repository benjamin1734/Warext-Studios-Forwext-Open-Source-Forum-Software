<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Discipline;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\Event\DomainEventDispatcher;
use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Forum\Moderation\ModerationAuditAction;
use Forwext\Core\Forum\Moderation\ModerationAuditEvent;
use Forwext\Core\Forum\Moderation\ModerationAuditStore;
use Forwext\Core\Forum\Moderation\ModerationReasonCode;
use Forwext\Core\Forum\Moderation\ModerationRequestId;
use InvalidArgumentException;

final readonly class DisciplineService
{
    public const VIEW_PERMISSION = 'moderation.discipline.view';
    public const WARNING_ISSUE_PERMISSION = 'moderation.warning.issue';
    public const WARNING_MANAGE_PERMISSION = 'moderation.warning.manage';
    public const RESTRICTION_MANAGE_PERMISSION = 'moderation.restriction.manage';
    public const BAN_MANAGE_PERMISSION = 'moderation.ban.manage';
    public const REVOKE_PERMISSION = 'moderation.discipline.revoke';

    public function __construct(
        private TransactionalQueryExecutor $database,
        private DisciplineRepository $discipline,
        private UserRepository $users,
        private PermissionGate $gate,
        private ModerationAuditStore $audit,
        private DisciplineNotifier $notifier,
        private ?DomainEventDispatcher $events = null,
    ) {
    }

    public function overview(int $limit = 100): DisciplineOverview
    {
        $this->requireView();
        return new DisciplineOverview(
            $this->discipline->warningDefinitions(true),
            $this->discipline->recent(DisciplineActionType::cases(), $limit),
        );
    }

    /** @return list<DisciplineAction> */
    public function forUser(EntityId $userId, int $limit = 100): array
    {
        $this->requireView();
        $this->requireUser($userId);
        return $this->discipline->forUser($userId, $limit);
    }

    public function activePoints(EntityId $userId, ?DateTimeImmutable $at = null): int
    {
        $this->requireView();
        $this->requireUser($userId);
        return $this->discipline->activePoints($userId, self::utc($at));
    }

    public function saveWarningDefinition(
        WarningDefinition $definition,
        ModerationRequestId $requestId,
        ?DateTimeImmutable $at = null,
    ): void {
        $this->requireBase();
        $this->gate->require(PermissionKey::fromString(self::WARNING_MANAGE_PERMISSION));
        $at = self::utc($at);
        $before = $this->discipline->warningDefinition($definition->key);

        $this->database->transaction(function () use ($definition, $requestId, $at, $before): void {
            $this->discipline->saveWarningDefinition($definition, $at);
            $this->appendAudit(
                ModerationAuditAction::WarningDefinitionSave,
                'warning_definition',
                $definition->key,
                ModerationReasonCode::fromString('discipline.warning_definition'),
                $requestId,
                $before === null ? [] : self::definitionSnapshot($before),
                self::definitionSnapshot($definition),
                $at,
            );
        });
    }

    public function issueWarning(
        EntityId $userId,
        string $definitionKey,
        ModerationReasonCode $reason,
        string $reasonText,
        ModerationRequestId $requestId,
        ?DateTimeImmutable $at = null,
    ): DisciplineAction {
        $this->requireBase();
        $this->gate->require(PermissionKey::fromString(self::WARNING_ISSUE_PERMISSION));
        $definition = $this->discipline->warningDefinition(strtolower(trim($definitionKey)));
        if ($definition === null || !$definition->active) {
            throw new InvalidArgumentException('Warning definition is unavailable.');
        }
        $at = self::utc($at);
        $expiresAt = $definition->expiryDays === null
            ? null
            : $at->add(new DateInterval('P' . $definition->expiryDays . 'D'));

        return $this->issue(
            $userId,
            DisciplineActionType::Warning,
            $reason,
            $reasonText,
            $requestId,
            $at,
            $expiresAt,
            $definition->points,
            $definition->key,
            [],
            ModerationAuditAction::WarningIssue,
        );
    }

    public function restrict(
        EntityId $userId,
        DisciplineRestrictionKey $restriction,
        ModerationReasonCode $reason,
        string $reasonText,
        ModerationRequestId $requestId,
        ?DateTimeImmutable $expiresAt = null,
        ?DateTimeImmutable $at = null,
    ): DisciplineAction {
        $this->requireBase();
        $this->gate->require(PermissionKey::fromString(self::RESTRICTION_MANAGE_PERMISSION));
        $at = self::utc($at);
        return $this->issue(
            $userId,
            DisciplineActionType::Restriction,
            $reason,
            $reasonText,
            $requestId,
            $at,
            $expiresAt?->setTimezone(new DateTimeZone('UTC')),
            0,
            null,
            [$restriction],
            ModerationAuditAction::RestrictionApply,
        );
    }

    public function suspend(
        EntityId $userId,
        ModerationReasonCode $reason,
        string $reasonText,
        ModerationRequestId $requestId,
        DateTimeImmutable $expiresAt,
        ?DateTimeImmutable $at = null,
    ): DisciplineAction {
        $this->requireBase();
        $this->gate->require(PermissionKey::fromString(self::BAN_MANAGE_PERMISSION));
        $at = self::utc($at);
        return $this->issue(
            $userId,
            DisciplineActionType::Suspension,
            $reason,
            $reasonText,
            $requestId,
            $at,
            $expiresAt->setTimezone(new DateTimeZone('UTC')),
            0,
            null,
            [],
            ModerationAuditAction::SuspensionApply,
        );
    }

    public function ban(
        EntityId $userId,
        ModerationReasonCode $reason,
        string $reasonText,
        ModerationRequestId $requestId,
        ?DateTimeImmutable $expiresAt = null,
        ?DateTimeImmutable $at = null,
    ): DisciplineAction {
        $this->requireBase();
        $this->gate->require(PermissionKey::fromString(self::BAN_MANAGE_PERMISSION));
        $at = self::utc($at);
        return $this->issue(
            $userId,
            DisciplineActionType::Ban,
            $reason,
            $reasonText,
            $requestId,
            $at,
            $expiresAt?->setTimezone(new DateTimeZone('UTC')),
            0,
            null,
            [],
            ModerationAuditAction::BanApply,
        );
    }

    public function revoke(
        EntityId $actionId,
        string $reason,
        ModerationRequestId $requestId,
        ?DateTimeImmutable $at = null,
    ): DisciplineAction {
        $this->requireBase();
        $this->gate->require(PermissionKey::fromString(self::REVOKE_PERMISSION));
        $before = $this->discipline->action($actionId)
            ?? throw new InvalidArgumentException('Discipline action was not found.');
        $at = self::utc($at);
        if (!$before->isActiveAt($at)) {
            throw new InvalidArgumentException('Only an active discipline action can be revoked.');
        }

        $after = $this->database->transaction(function () use ($actionId, $reason, $requestId, $at, $before): DisciplineAction {
            $after = $this->discipline->revoke($actionId, $this->gate->actorId(), $reason, $at);
            $this->appendAudit(
                ModerationAuditAction::DisciplineRevoke,
                'discipline_action',
                $actionId->value(),
                ModerationReasonCode::fromString('discipline.revoke'),
                $requestId,
                self::actionSnapshot($before),
                self::actionSnapshot($after),
                $at,
            );
            $this->notifier->revoked($after);
            return $after;
        });

        return $after;
    }

    private function issue(
        EntityId $userId,
        DisciplineActionType $type,
        ModerationReasonCode $reason,
        string $reasonText,
        ModerationRequestId $requestId,
        DateTimeImmutable $at,
        ?DateTimeImmutable $expiresAt,
        int $points,
        ?string $warningDefinitionKey,
        array $restrictions,
        ModerationAuditAction $auditAction,
    ): DisciplineAction {
        $this->requireUser($userId);
        if ($userId->equals($this->gate->actorId())) {
            throw new InvalidArgumentException('Moderators cannot apply discipline actions to their own account.');
        }
        $reasonText = trim($reasonText);
        if ($reasonText === '' || strlen($reasonText) > 2000) {
            throw new InvalidArgumentException('Discipline reason text must contain 1-2000 bytes.');
        }

        $action = new DisciplineAction(
            EntityId::fromString(bin2hex(random_bytes(16))),
            $userId,
            $this->gate->actorId(),
            $type,
            $reason,
            $reasonText,
            $points,
            $warningDefinitionKey,
            $restrictions,
            true,
            $at,
            $expiresAt,
        );

        $this->database->transaction(function () use ($action, $requestId, $auditAction): void {
            $this->discipline->insertAction($action);
            $this->appendAudit(
                $auditAction,
                'discipline_action',
                $action->actionId->value(),
                $action->reasonCode,
                $requestId,
                [],
                self::actionSnapshot($action),
                $action->startsAt,
            );
            $this->notifier->issued($action);
        });

        $appealReference = $action->appealReference();
        if ($appealReference !== null && $this->events !== null) {
            $this->events->dispatch(new DisciplineAppealHookEvent(
                $action->actionId,
                $action->userId,
                $action->type,
                $appealReference,
                $action->startsAt,
            ));
        }

        return $action;
    }

    private function requireView(): void
    {
        $this->requireBase();
        $this->gate->require(PermissionKey::fromString(self::VIEW_PERMISSION));
    }

    private function requireBase(): void
    {
        $this->gate->require(PermissionKey::fromString('moderation.access'));
    }

    private function requireUser(EntityId $userId): void
    {
        if ($this->users->find($userId) === null) {
            throw new InvalidArgumentException('Discipline target user was not found.');
        }
    }

    private function appendAudit(
        ModerationAuditAction $action,
        string $targetType,
        string $targetId,
        ModerationReasonCode $reason,
        ModerationRequestId $requestId,
        array $before,
        array $after,
        DateTimeImmutable $at,
    ): void {
        $this->audit->append(new ModerationAuditEvent(
            ModerationAuditEvent::generateId(),
            $this->gate->actorId(),
            $action,
            $targetType,
            $targetId,
            null,
            $reason,
            $requestId,
            $before,
            $after,
            $at,
        ));
    }

    /** @return array<string,bool|int|float|string|null|list<string>> */
    private static function definitionSnapshot(WarningDefinition $definition): array
    {
        return [
            'key' => $definition->key,
            'label' => $definition->label,
            'points' => $definition->points,
            'expiry_days' => $definition->expiryDays,
            'active' => $definition->active,
            'sort_order' => $definition->sortOrder,
        ];
    }

    /** @return array<string,bool|int|float|string|null|list<string>> */
    private static function actionSnapshot(DisciplineAction $action): array
    {
        return [
            'user_id' => $action->userId->value(),
            'action_type' => $action->type->value,
            'reason_code' => $action->reasonCode->value(),
            'points' => $action->points,
            'warning_definition_key' => $action->warningDefinitionKey,
            'restrictions' => array_map(
                static fn (DisciplineRestrictionKey $restriction): string => $restriction->value,
                $action->restrictions,
            ),
            'appealable' => $action->appealable,
            'starts_at' => $action->startsAt->format('Y-m-d\TH:i:s.u\Z'),
            'expires_at' => $action->expiresAt?->format('Y-m-d\TH:i:s.u\Z'),
            'revoked_at' => $action->revokedAt?->format('Y-m-d\TH:i:s.u\Z'),
            'revoked_by_user_id' => $action->revokedByUserId?->value(),
            'revoke_reason' => $action->revokeReason,
        ];
    }

    private static function utc(?DateTimeImmutable $at): DateTimeImmutable
    {
        return ($at ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
    }
}
