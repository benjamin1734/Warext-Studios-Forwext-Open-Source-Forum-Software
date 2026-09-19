<?php

declare(strict_types=1);

namespace Forwext\Core\Giveaway;

use DateTimeImmutable;
use DateTimeZone;
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
use Forwext\Core\Search\Lifecycle\SearchIndexChangeStore;

final readonly class GiveawayService
{
    public const SEARCH_TYPE = 'giveaway.item';

    public function __construct(
        private TransactionalQueryExecutor $database,
        private GiveawayRepository $giveaways,
        private PermissionAuthorizer $authorizer,
        private AuditRecorder $audit,
        private SearchIndexChangeStore $searchChanges,
    ) {
    }

    /** @return list<Giveaway> */
    public function visible(EntityId $actor, int $limit = 100): array
    {
        $this->require($actor, 'giveaway.view');
        return $this->giveaways->list(publicOnly:true, limit:$limit);
    }

    /** @return list<Giveaway> */
    public function owned(EntityId $actor, int $limit = 100): array
    {
        if (!$this->allows($actor, 'giveaway.create') && !$this->allows($actor, 'giveaway.manage')) {
            $this->require($actor, 'giveaway.create');
        }
        return $this->giveaways->list($actor, false, $limit);
    }

    public function find(EntityId $actor, EntityId $giveawayId): Giveaway
    {
        $giveaway = $this->giveaways->find($giveawayId)
            ?? throw new GiveawayException('Giveaway was not found.');

        if (in_array($giveaway->state, [GiveawayState::Scheduled, GiveawayState::Open, GiveawayState::Closed], true)) {
            $this->require($actor, 'giveaway.view');
            return $giveaway;
        }
        if (!$this->canManageGiveaway($actor, $giveaway)) {
            $this->require($actor, 'giveaway.manage');
        }
        return $giveaway;
    }

    public function saveDraft(
        EntityId $actor,
        Giveaway $candidate,
        DateTimeImmutable $at,
        ?AuditRequestId $requestId = null,
    ): Giveaway {
        $existing = $this->giveaways->find($candidate->giveawayId);
        if ($existing === null) {
            $this->require($actor, 'giveaway.create');
            if (!$candidate->ownerUserId->equals($actor)) {
                $this->require($actor, 'giveaway.manage');
            }
        } else {
            if (!$this->canManageGiveaway($actor, $existing)) {
                $this->require($actor, 'giveaway.manage');
            }
            if ($existing->state->isTerminal() || $existing->state === GiveawayState::Open) {
                throw new GiveawayException('Open or terminal giveaways cannot be edited as a draft.');
            }
            if (!$candidate->ownerUserId->equals($existing->ownerUserId)) {
                throw new GiveawayException('Giveaway ownership cannot be changed.');
            }
        }

        $now = self::utc($at);
        $draft = new Giveaway(
            $candidate->giveawayId,
            $candidate->ownerUserId,
            $candidate->slug,
            $candidate->title,
            $candidate->description,
            $candidate->prize,
            $candidate->participationTerms,
            $candidate->startsAt,
            $candidate->endsAt,
            $candidate->entriesPerUser,
            $candidate->maxParticipants,
            GiveawayState::Draft,
            $existing?->createdAt ?? $candidate->createdAt,
            $now,
        );
        $this->auditedSave(
            $actor,
            $existing,
            $draft,
            $existing === null ? 'giveaway.create' : 'giveaway.update',
            $requestId ?? AuditRequestId::generate(),
            $now,
        );
        return $draft;
    }

    public function publish(
        EntityId $actor,
        EntityId $giveawayId,
        DateTimeImmutable $at,
        ?AuditRequestId $requestId = null,
    ): Giveaway {
        $current = $this->giveaways->find($giveawayId)
            ?? throw new GiveawayException('Giveaway was not found.');
        if (!$this->canManageGiveaway($actor, $current)) {
            $this->require($actor, 'giveaway.manage');
        }
        if (!in_array($current->state, [GiveawayState::Draft, GiveawayState::Scheduled], true)) {
            throw new GiveawayException('Giveaway cannot be published from its current state.');
        }

        $target = $current->expectedPublishedState($at);
        if ($target === GiveawayState::Closed) {
            throw new GiveawayException('Giveaway end time has already passed.');
        }
        $published = $this->withState($current, $target, $at);
        $this->auditedSave(
            $actor,
            $current,
            $published,
            'giveaway.publish',
            $requestId ?? AuditRequestId::generate(),
            $at,
        );
        return $published;
    }

    public function cancel(
        EntityId $actor,
        EntityId $giveawayId,
        DateTimeImmutable $at,
        ?AuditRequestId $requestId = null,
    ): Giveaway {
        $current = $this->giveaways->find($giveawayId)
            ?? throw new GiveawayException('Giveaway was not found.');
        if (!$this->canManageGiveaway($actor, $current)) {
            $this->require($actor, 'giveaway.manage');
        }
        if ($current->state->isTerminal()) {
            throw new GiveawayException('Terminal giveaway cannot be cancelled again.');
        }

        $cancelled = $this->withState($current, GiveawayState::Cancelled, $at);
        $this->auditedSave(
            $actor,
            $current,
            $cancelled,
            'giveaway.cancel',
            $requestId ?? AuditRequestId::generate(),
            $at,
        );
        return $cancelled;
    }

    public function syncDue(DateTimeImmutable $at, int $limit = 100): int
    {
        $changed = 0;
        foreach ($this->giveaways->dueTransitions($at, $limit) as $current) {
            $target = $current->expectedPublishedState($at);
            if ($target === $current->state
                || !in_array($current->state, [GiveawayState::Scheduled, GiveawayState::Open], true)
            ) {
                continue;
            }
            if ($current->state === GiveawayState::Open && $target !== GiveawayState::Closed) {
                continue;
            }
            if ($current->state === GiveawayState::Scheduled
                && !in_array($target, [GiveawayState::Open, GiveawayState::Closed], true)
            ) {
                continue;
            }

            $updated = $this->withState($current, $target, $at);
            $this->database->transaction(function () use ($updated): void {
                $this->giveaways->save($updated);
                $this->searchChanges->record(self::SEARCH_TYPE, $updated->giveawayId->value());
            });
            ++$changed;
        }
        return $changed;
    }

    public function canCreate(EntityId $actor): bool
    {
        return $this->allows($actor, 'giveaway.create');
    }

    public function canManage(EntityId $actor): bool
    {
        return $this->allows($actor, 'giveaway.manage');
    }

    public function canManageGiveaway(EntityId $actor, Giveaway $giveaway): bool
    {
        return $this->allows($actor, 'giveaway.manage')
            || ($giveaway->ownerUserId->equals($actor) && $this->allows($actor, 'giveaway.create'));
    }

    private function auditedSave(
        EntityId $actor,
        ?Giveaway $before,
        Giveaway $after,
        string $action,
        AuditRequestId $requestId,
        DateTimeImmutable $at,
    ): void {
        $event = new AuditEvent(
            AuditEvent::generateId(),
            AuditScope::Administration,
            $actor,
            AuditAction::fromString($action),
            'giveaway.item',
            $after->giveawayId->value(),
            null,
            $action,
            $requestId,
            $before === null ? [] : self::snapshot($before),
            self::snapshot($after),
            $at,
        );
        $this->audit->mutate($event, function () use ($after): void {
            $this->giveaways->save($after);
            $this->searchChanges->record(self::SEARCH_TYPE, $after->giveawayId->value());
        });
    }

    private function withState(Giveaway $giveaway, GiveawayState $state, DateTimeImmutable $at): Giveaway
    {
        return new Giveaway(
            $giveaway->giveawayId,
            $giveaway->ownerUserId,
            $giveaway->slug,
            $giveaway->title,
            $giveaway->description,
            $giveaway->prize,
            $giveaway->participationTerms,
            $giveaway->startsAt,
            $giveaway->endsAt,
            $giveaway->entriesPerUser,
            $giveaway->maxParticipants,
            $state,
            $giveaway->createdAt,
            self::utc($at),
        );
    }

    /** @return array<string,scalar|null> */
    private static function snapshot(Giveaway $giveaway): array
    {
        return [
            'state'=>$giveaway->state->value,
            'title'=>$giveaway->title,
            'starts_at'=>$giveaway->startsAt->format(DATE_ATOM),
            'ends_at'=>$giveaway->endsAt->format(DATE_ATOM),
            'prize_title'=>$giveaway->prize->title,
            'prize_quantity'=>$giveaway->prize->quantity,
            'entries_per_user'=>$giveaway->entriesPerUser,
            'max_participants'=>$giveaway->maxParticipants,
        ];
    }

    private function require(EntityId $actor, string $permission): void
    {
        $decision = $this->authorizer->resolve($actor, PermissionKey::fromString($permission));
        if (!$decision->isAllowed()) {
            throw new PermissionDeniedException($decision);
        }
    }

    private function allows(EntityId $actor, string $permission): bool
    {
        return $this->authorizer->allows($actor, PermissionKey::fromString($permission));
    }

    private static function utc(DateTimeImmutable $date): DateTimeImmutable
    {
        return $date->setTimezone(new DateTimeZone('UTC'));
    }
}
