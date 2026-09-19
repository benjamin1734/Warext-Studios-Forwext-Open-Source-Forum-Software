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
use Forwext\Core\Notification\NotificationException;

final readonly class GiveawayDrawService
{
    public function __construct(
        private TransactionalQueryExecutor $database,
        private GiveawayRepository $giveaways,
        private GiveawayParticipationRepository $participation,
        private GiveawayDrawRepository $draws,
        private PermissionAuthorizer $authorizer,
        private AuditRecorder $audit,
        private GiveawayNotifier $notifier,
        private GiveawayDrawAlgorithm $algorithm = new GiveawayDrawAlgorithm(),
    ) {
    }

    public function draw(
        EntityId $actor,
        EntityId $giveawayId,
        DateTimeImmutable $at,
        ?AuditRequestId $requestId = null,
    ): GiveawayDraw {
        $this->requireManage($actor);
        $giveaway = $this->giveaways->find($giveawayId)
            ?? throw new GiveawayException('Giveaway was not found.');
        $this->assertClosed($giveaway);

        return $this->create(
            $actor,
            $giveawayId,
            GiveawayDrawKind::Primary,
            null,
            $at,
            $requestId,
        );
    }

    public function redraw(
        EntityId $actor,
        EntityId $giveawayId,
        string $reason,
        DateTimeImmutable $at,
        ?AuditRequestId $requestId = null,
    ): GiveawayDraw {
        $this->requireManage($actor);
        $reason = GiveawayDraw::assertRedrawReason($reason);
        $giveaway = $this->giveaways->find($giveawayId)
            ?? throw new GiveawayException('Giveaway was not found.');
        $this->assertClosed($giveaway);

        return $this->create(
            $actor,
            $giveawayId,
            GiveawayDrawKind::Redraw,
            $reason,
            $at,
            $requestId,
        );
    }

    /** @return list<GiveawayDrawProof> */
    public function proof(EntityId $viewer, EntityId $giveawayId): array
    {
        $this->require($viewer, 'giveaway.view');
        if ($this->giveaways->find($giveawayId) === null) {
            throw new GiveawayException('Giveaway was not found.');
        }

        $history = $this->draws->history($giveawayId);
        $proofs = [];
        $previous = null;

        foreach ($history as $index=>$draw) {
            $population = $this->draws->population($draw->drawId);
            $lineageValid = $index === 0
                ? $draw->kind === GiveawayDrawKind::Primary
                    && $draw->sequence === 1
                    && $draw->parentDrawId === null
                : $draw->kind === GiveawayDrawKind::Redraw
                    && $draw->sequence === $index + 1
                    && $previous !== null
                    && $draw->parentDrawId?->equals($previous->drawId) === true;

            $proofs[] = new GiveawayDrawProof(
                $draw,
                $lineageValid && $this->algorithm->verifies($draw, $population),
                $index === count($history) - 1,
            );
            $previous = $draw;
        }

        return $proofs;
    }

    private function create(
        EntityId $actor,
        EntityId $giveawayId,
        GiveawayDrawKind $kind,
        ?string $reason,
        DateTimeImmutable $at,
        ?AuditRequestId $requestId,
    ): GiveawayDraw {
        return $this->database->transaction(function () use (
            $actor, $giveawayId, $kind, $reason, $at, $requestId,
        ): GiveawayDraw {
            if (!$this->participation->lockGiveaway($giveawayId)) {
                throw new GiveawayException('Giveaway was not found.');
            }

            $giveaway = $this->giveaways->find($giveawayId)
                ?? throw new GiveawayException('Giveaway was not found.');
            $this->assertClosed($giveaway);

            $history = $this->draws->history($giveawayId);
            $latest = $history === [] ? null : $history[count($history) - 1];
            if ($kind === GiveawayDrawKind::Primary && $latest !== null) {
                throw new GiveawayDrawException('Giveaway already has a draw. Use the explicit redraw workflow.');
            }
            if ($kind === GiveawayDrawKind::Redraw && $latest === null) {
                throw new GiveawayDrawException('A primary draw must exist before a redraw.');
            }

            $excluded = [];
            foreach ($history as $existing) {
                $excluded[$existing->winnerUserId->value()] = true;
            }

            $population = [];
            foreach ($this->participation->entriesForDraw($giveawayId) as $entry) {
                if (isset($excluded[$entry->userId->value()])) {
                    continue;
                }
                $population[] = new GiveawayDrawCandidate(
                    $entry->entryId,
                    $entry->userId,
                    $entry->entryCount,
                );
            }
            if ($population === []) {
                throw new GiveawayDrawException('No unused eligible participants remain for this draw.');
            }

            $seed = bin2hex(random_bytes(32));
            $selection = $this->algorithm->select($population, $seed);
            $draw = new GiveawayDraw(
                GiveawayDraw::generateId(),
                $giveawayId,
                $latest === null ? 1 : $latest->sequence + 1,
                $kind,
                $kind === GiveawayDrawKind::Redraw ? $latest?->drawId : null,
                $reason,
                $seed,
                $selection->populationHash,
                $selection->participantCount,
                $selection->totalWeight,
                $selection->selectedTicket,
                $selection->winner->userId,
                $selection->winner->entryId,
                $selection->winner->weight,
                $actor,
                self::utc($at),
            );

            $event = new AuditEvent(
                AuditEvent::generateId(),
                AuditScope::Administration,
                $actor,
                AuditAction::fromString($kind === GiveawayDrawKind::Primary ? 'giveaway.draw' : 'giveaway.redraw'),
                'giveaway.draw',
                $draw->drawId->value(),
                null,
                $kind === GiveawayDrawKind::Primary ? 'giveaway.draw.primary' : 'giveaway.draw.redraw',
                $requestId ?? AuditRequestId::generate(),
                $latest === null ? [] : [
                    'previous_draw_id'=>$latest->drawId->value(),
                    'previous_winner_user_id'=>$latest->winnerUserId->value(),
                ],
                [
                    'giveaway_id'=>$giveawayId->value(),
                    'sequence'=>$draw->sequence,
                    'kind'=>$draw->kind->value,
                    'population_hash'=>$draw->populationHash,
                    'participant_count'=>$draw->participantCount,
                    'total_weight'=>$draw->totalWeight,
                    'selected_ticket'=>$draw->selectedTicket,
                    'winner_user_id'=>$draw->winnerUserId->value(),
                    'proof_hash'=>$draw->proofHash,
                ],
                self::utc($at),
            );

            $this->draws->save($draw, $population);
            $this->audit->append($event);

            if ($latest !== null) {
                try {
                    $this->notifier->replaced($latest, $giveaway);
                } catch (NotificationException) {
                    // A removed/unavailable prior winner must not block a valid, fully audited redraw.
                }
            }
            $this->notifier->winner($draw, $giveaway);

            return $draw;
        });
    }

    private function assertClosed(Giveaway $giveaway): void
    {
        if ($giveaway->state !== GiveawayState::Closed) {
            throw new GiveawayDrawException('Winner selection requires a closed giveaway.');
        }
    }

    private function requireManage(EntityId $actor): void
    {
        $this->require($actor, 'giveaway.manage');
    }

    private function require(EntityId $actor, string $permission): void
    {
        $decision = $this->authorizer->resolve($actor, PermissionKey::fromString($permission));
        if (!$decision->isAllowed()) {
            throw new PermissionDeniedException($decision);
        }
    }

    private static function utc(DateTimeImmutable $date): DateTimeImmutable
    {
        return $date->setTimezone(new DateTimeZone('UTC'));
    }
}
