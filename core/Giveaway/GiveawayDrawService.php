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
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Notification\NotificationException;

final readonly class GiveawayDrawService
{
    public function __construct(
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

        if ($this->draws->latest($giveawayId) !== null) {
            throw new GiveawayDrawException('Giveaway already has a draw. Use the explicit redraw workflow.');
        }

        return $this->create(
            $actor,
            $giveaway,
            GiveawayDrawKind::Primary,
            null,
            null,
            [],
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

        $history = $this->draws->history($giveawayId);
        $previous = $history === [] ? null : $history[count($history) - 1];
        if ($previous === null) {
            throw new GiveawayDrawException('A primary draw must exist before a redraw.');
        }

        return $this->create(
            $actor,
            $giveaway,
            GiveawayDrawKind::Redraw,
            $previous,
            $reason,
            $history,
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

        $base = $this->participation->entriesForDraw($giveawayId);
        $history = $this->draws->history($giveawayId);
        $excluded = [];
        $proofs = [];
        $previous = null;

        foreach ($history as $index=>$draw) {
            $population = array_values(array_filter(
                $base,
                static fn (GiveawayEntry $entry): bool => !isset($excluded[$entry->userId->value()]),
            ));
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
            $excluded[$draw->winnerUserId->value()] = true;
            $previous = $draw;
        }

        return $proofs;
    }

    private function create(
        EntityId $actor,
        Giveaway $giveaway,
        GiveawayDrawKind $kind,
        ?GiveawayDraw $parent,
        ?string $reason,
        array $history,
        DateTimeImmutable $at,
        ?AuditRequestId $requestId,
    ): GiveawayDraw {
        if (!$this->participation->lockGiveaway($giveaway->giveawayId)) {
            throw new GiveawayException('Giveaway was not found.');
        }

        $latest = $this->draws->latest($giveaway->giveawayId);
        if ($kind === GiveawayDrawKind::Primary && $latest !== null) {
            throw new GiveawayDrawException('Giveaway already has a draw.');
        }
        if ($kind === GiveawayDrawKind::Redraw
            && ($latest === null || $parent === null || !$latest->drawId->equals($parent->drawId))
        ) {
            throw new GiveawayDrawException('Giveaway redraw lineage changed before selection.');
        }

        $excluded = [];
        foreach ($history as $existing) {
            if (!$existing instanceof GiveawayDraw) {
                throw new GiveawayDrawException('Giveaway draw history is invalid.');
            }
            $excluded[$existing->winnerUserId->value()] = true;
        }
        $entries = array_values(array_filter(
            $this->participation->entriesForDraw($giveaway->giveawayId),
            static fn (GiveawayEntry $entry): bool => !isset($excluded[$entry->userId->value()]),
        ));
        if ($entries === []) {
            throw new GiveawayDrawException('No unused eligible participants remain for this draw.');
        }

        $seed = bin2hex(random_bytes(32));
        $selection = $this->algorithm->select($entries, $seed);
        $sequence = $latest === null ? 1 : $latest->sequence + 1;
        $draw = new GiveawayDraw(
            GiveawayDraw::generateId(),
            $giveaway->giveawayId,
            $sequence,
            $kind,
            $parent?->drawId,
            $reason,
            $seed,
            $selection->populationHash,
            $selection->participantCount,
            $selection->totalWeight,
            $selection->selectedTicket,
            $selection->winner->userId,
            $selection->winner->entryId,
            $selection->winner->entryCount,
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
            $parent === null ? [] : [
                'previous_draw_id'=>$parent->drawId->value(),
                'previous_winner_user_id'=>$parent->winnerUserId->value(),
            ],
            [
                'giveaway_id'=>$giveaway->giveawayId->value(),
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

        return $this->audit->mutate($event, function () use ($draw, $giveaway, $parent): GiveawayDraw {
            $this->draws->save($draw);
            if ($parent !== null) {
                try {
                    $this->notifier->replaced($parent, $giveaway);
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
