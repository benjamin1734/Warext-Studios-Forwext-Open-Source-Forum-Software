<?php

declare(strict_types=1);

namespace Forwext\Core\Giveaway;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class GiveawayDraw
{
    public const ALGORITHM = 'sha256-rejection-63-v1';

    public DateTimeImmutable $createdAt;
    public string $proofHash;

    public function __construct(
        public EntityId $drawId,
        public EntityId $giveawayId,
        public int $sequence,
        public GiveawayDrawKind $kind,
        public ?EntityId $parentDrawId,
        public ?string $redrawReason,
        public string $seedHex,
        public string $populationHash,
        public int $participantCount,
        public int $totalWeight,
        public int $selectedTicket,
        public EntityId $winnerUserId,
        public EntityId $winnerEntryId,
        public int $winnerEntryWeight,
        public ?EntityId $createdByUserId,
        DateTimeImmutable $createdAt,
        ?string $storedProofHash = null,
    ) {
        foreach ([$this->drawId, $this->giveawayId, $this->winnerEntryId] as $id) {
            if (preg_match('/^[a-f0-9]{32}$/D', $id->value()) !== 1) {
                throw new InvalidArgumentException('Giveaway draw identifier is invalid.');
            }
        }
        UserId::assert($this->winnerUserId);
        if ($this->createdByUserId !== null) {
            UserId::assert($this->createdByUserId);
        }
        if ($this->parentDrawId !== null && preg_match('/^[a-f0-9]{32}$/D', $this->parentDrawId->value()) !== 1) {
            throw new InvalidArgumentException('Giveaway parent draw id is invalid.');
        }
        if ($this->sequence < 1 || $this->sequence > 1_000_000) {
            throw new InvalidArgumentException('Giveaway draw sequence is invalid.');
        }
        if ($this->kind === GiveawayDrawKind::Primary) {
            if ($this->sequence !== 1 || $this->parentDrawId !== null || $this->redrawReason !== null) {
                throw new InvalidArgumentException('Primary giveaway draw lineage is invalid.');
            }
        } else {
            if ($this->sequence < 2 || $this->parentDrawId === null || $this->redrawReason === null) {
                throw new InvalidArgumentException('Giveaway redraw lineage is invalid.');
            }
            self::reason($this->redrawReason);
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $this->seedHex) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $this->populationHash) !== 1
        ) {
            throw new InvalidArgumentException('Giveaway draw cryptographic proof material is invalid.');
        }
        if ($this->participantCount < 1 || $this->participantCount > 100_000_000
            || $this->totalWeight < 1 || $this->selectedTicket < 1 || $this->selectedTicket > $this->totalWeight
            || $this->winnerEntryWeight < 1 || $this->winnerEntryWeight > 1000
        ) {
            throw new InvalidArgumentException('Giveaway draw population or ticket values are invalid.');
        }

        $this->createdAt = $createdAt->setTimezone(new DateTimeZone('UTC'));
        $this->proofHash = $this->calculateProofHash();
        if ($storedProofHash !== null && !hash_equals($this->proofHash, $storedProofHash)) {
            throw new InvalidArgumentException('Stored giveaway draw proof hash does not match the record.');
        }
    }

    public static function generateId(): EntityId
    {
        return EntityId::fromString(bin2hex(random_bytes(16)));
    }

    public static function assertRedrawReason(string $reason): string
    {
        self::reason($reason);
        return trim($reason);
    }

    private static function reason(string $reason): void
    {
        $reason = trim($reason);
        if (strlen($reason) < 10 || strlen($reason) > 500 || preg_match('//u', $reason) !== 1
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason) === 1
        ) {
            throw new InvalidArgumentException('Giveaway redraw reason must contain 10-500 valid UTF-8 bytes.');
        }
    }

    private function calculateProofHash(): string
    {
        return hash('sha256', implode("\n", [
            'forwext-giveaway-draw-proof-v1',
            $this->drawId->value(),
            $this->giveawayId->value(),
            (string) $this->sequence,
            $this->kind->value,
            $this->parentDrawId?->value() ?? '-',
            $this->redrawReason ?? '-',
            self::ALGORITHM,
            $this->seedHex,
            $this->populationHash,
            (string) $this->participantCount,
            (string) $this->totalWeight,
            (string) $this->selectedTicket,
            $this->winnerUserId->value(),
            $this->winnerEntryId->value(),
            (string) $this->winnerEntryWeight,
            $this->createdByUserId?->value() ?? '-',
            $this->createdAt->format('Y-m-d\TH:i:s.u\Z'),
        ]));
    }
}
