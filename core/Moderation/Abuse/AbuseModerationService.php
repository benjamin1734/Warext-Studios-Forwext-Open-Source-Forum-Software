<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Abuse;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Moderation\BulkPostAction;
use Forwext\Core\Forum\Moderation\BulkThreadAction;
use Forwext\Core\Forum\Moderation\ContentModerationService;
use Forwext\Core\Forum\Moderation\ModerationAuditAction;
use Forwext\Core\Forum\Moderation\ModerationAuditEvent;
use Forwext\Core\Forum\Moderation\ModerationAuditStore;
use Forwext\Core\Forum\Moderation\ModerationReasonCode;
use Forwext\Core\Forum\Moderation\ModerationRequestId;
use InvalidArgumentException;

final readonly class AbuseModerationService
{
    public const VIEW_PERMISSION = 'moderation.abuse.view';
    public const MANAGE_RULES_PERMISSION = 'moderation.abuse.manage_rules';
    public const CLEANUP_PERMISSION = 'moderation.abuse.cleanup';

    public function __construct(
        private TransactionalQueryExecutor $database,
        private AbuseRepository $repository,
        private ContentModerationService $contentModeration,
        private PermissionGate $gate,
        private ModerationAuditStore $audit,
    ) {
    }

    public function overview(int $limit = 100): AbuseOverview
    {
        $this->requireView();
        return new AbuseOverview(
            $this->repository->allRules(),
            $this->repository->unresolved($limit),
        );
    }

    public function saveRule(
        AbuseRule $rule,
        ModerationRequestId $requestId,
        ?DateTimeImmutable $at = null,
    ): void {
        $this->requireBase();
        $this->gate->require(PermissionKey::fromString(self::MANAGE_RULES_PERMISSION));
        $at = self::utc($at);
        $this->database->transaction(function () use ($rule, $requestId, $at): void {
            $before = $this->repository->rule($rule->key);
            $this->repository->saveRule($rule, $at);
            $this->audit->append(new ModerationAuditEvent(
                ModerationAuditEvent::generateId(),
                $this->gate->actorId(),
                ModerationAuditAction::AbuseRuleSave,
                'abuse_rule',
                $rule->key,
                null,
                ModerationReasonCode::fromString('abuse.rule'),
                $requestId,
                $before === null ? [] : self::ruleSnapshot($before),
                self::ruleSnapshot($rule),
                $at,
            ));
        });
    }

    /** @param list<EntityId> $eventIds */
    public function cleanup(
        array $eventIds,
        ModerationReasonCode $reason,
        ModerationRequestId $requestId,
        ?DateTimeImmutable $at = null,
    ): int {
        $this->requireBase();
        $this->gate->require(PermissionKey::fromString(self::CLEANUP_PERMISSION));
        $at = self::utc($at);
        $events = $this->events($eventIds);
        $threads = [];
        $posts = [];

        foreach ($events as $event) {
            if ($event->targetType === 'forum.thread' && $event->targetId !== null) {
                $threads[$event->targetId->value()] = $event->targetId;
                continue;
            }
            if ($event->targetType === 'forum.post' && $event->targetId !== null) {
                $posts[$event->targetId->value()] = $event->targetId;
                continue;
            }
            throw new AbuseOperationException('Selected abuse event does not point to cleanable forum content.');
        }

        return $this->database->transaction(function () use (
            $threads,
            $posts,
            $events,
            $reason,
            $requestId,
            $at,
        ): int {
            if ($threads !== []) {
                $this->contentModeration->bulkThreads(
                    BulkThreadAction::Delete,
                    array_values($threads),
                    $reason,
                    $requestId,
                    $at,
                );
            }
            if ($posts !== []) {
                $this->contentModeration->bulkPosts(
                    BulkPostAction::Delete,
                    array_values($posts),
                    $reason,
                    $requestId,
                    $at,
                );
            }

            foreach ($events as $event) {
                $this->repository->resolve($event->eventId, $this->gate->actorId(), 'content_deleted', $at);
                $this->auditResolve($event, 'content_deleted', $reason, $requestId, $at);
            }
            return count($events);
        });
    }

    /** @param list<EntityId> $eventIds */
    public function dismiss(
        array $eventIds,
        ModerationReasonCode $reason,
        ModerationRequestId $requestId,
        ?DateTimeImmutable $at = null,
    ): int {
        $this->requireBase();
        $this->gate->require(PermissionKey::fromString(self::CLEANUP_PERMISSION));
        $at = self::utc($at);
        $events = $this->events($eventIds);
        return $this->database->transaction(function () use ($events, $reason, $requestId, $at): int {
            foreach ($events as $event) {
                $this->repository->resolve($event->eventId, $this->gate->actorId(), 'dismissed', $at);
                $this->auditResolve($event, 'dismissed', $reason, $requestId, $at);
            }
            return count($events);
        });
    }

    /** @param list<EntityId> $eventIds @return list<AbuseEvent> */
    private function events(array $eventIds): array
    {
        if ($eventIds === [] || count($eventIds) > 100) {
            throw new InvalidArgumentException('Abuse event selection must contain between 1 and 100 records.');
        }
        $events = [];
        foreach ($eventIds as $eventId) {
            if (!$eventId instanceof EntityId) {
                throw new InvalidArgumentException('Abuse event selection contains an invalid identifier.');
            }
            if (isset($events[$eventId->value()])) {
                continue;
            }
            $event = $this->repository->event($eventId)
                ?? throw new AbuseOperationException('Selected abuse event is unavailable.');
            if ($event->isResolved()) {
                throw new AbuseOperationException('Selected abuse event is already resolved.');
            }
            $events[$eventId->value()] = $event;
        }
        return array_values($events);
    }

    private function auditResolve(
        AbuseEvent $event,
        string $resolution,
        ModerationReasonCode $reason,
        ModerationRequestId $requestId,
        DateTimeImmutable $at,
    ): void {
        $this->audit->append(new ModerationAuditEvent(
            ModerationAuditEvent::generateId(),
            $this->gate->actorId(),
            ModerationAuditAction::AbuseEventResolve,
            'abuse_event',
            $event->eventId->value(),
            null,
            $reason,
            $requestId,
            ['resolution' => null],
            ['resolution' => $resolution, 'target_type' => $event->targetType, 'target_id' => $event->targetId?->value()],
            $at,
        ));
    }

    /** @return array<string,bool|int|float|string|null|list<string>> */
    private static function ruleSnapshot(AbuseRule $rule): array
    {
        return [
            'event_type' => $rule->eventType->value,
            'signal' => $rule->signal->value,
            'limit' => $rule->limit,
            'window_seconds' => $rule->windowSeconds,
            'action' => $rule->action->value,
            'active' => $rule->active,
            'priority' => $rule->priority,
        ];
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

    private static function utc(?DateTimeImmutable $at): DateTimeImmutable
    {
        return ($at ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
    }
}
