<?php

declare(strict_types=1);

namespace Forwext\Core\Giveaway;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class Giveaway
{
    public DateTimeImmutable $startsAt;
    public DateTimeImmutable $endsAt;
    public DateTimeImmutable $createdAt;
    public DateTimeImmutable $updatedAt;

    public function __construct(
        public EntityId $giveawayId,
        public EntityId $ownerUserId,
        public string $slug,
        public string $title,
        public string $description,
        public GiveawayPrize $prize,
        public string $participationTerms,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $endsAt,
        public int $entriesPerUser,
        public ?int $maxParticipants,
        public GiveawayState $state,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ) {
        UserId::assert($this->ownerUserId);
        if (preg_match('/^[a-f0-9]{32}$/D', $this->giveawayId->value()) !== 1) {
            throw new InvalidArgumentException('Giveaway id is invalid.');
        }
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,159}$/D', $this->slug) !== 1) {
            throw new InvalidArgumentException('Giveaway slug is invalid.');
        }
        self::text($this->title, 180, 'title', false);
        self::text($this->description, 100000, 'description', false);
        self::text($this->participationTerms, 20000, 'participation terms', false);
        if ($this->entriesPerUser < 1 || $this->entriesPerUser > 1000) {
            throw new InvalidArgumentException('Giveaway entries per user is invalid.');
        }
        if ($this->maxParticipants !== null
            && ($this->maxParticipants < 1 || $this->maxParticipants > 100_000_000)
        ) {
            throw new InvalidArgumentException('Giveaway maximum participant count is invalid.');
        }

        $utc = new DateTimeZone('UTC');
        $this->startsAt = $startsAt->setTimezone($utc);
        $this->endsAt = $endsAt->setTimezone($utc);
        $this->createdAt = $createdAt->setTimezone($utc);
        $this->updatedAt = $updatedAt->setTimezone($utc);
        if ($this->endsAt <= $this->startsAt) {
            throw new InvalidArgumentException('Giveaway end time must be after start time.');
        }
        if ($this->updatedAt < $this->createdAt) {
            throw new InvalidArgumentException('Giveaway update time is invalid.');
        }
    }

    public static function generateId(): EntityId
    {
        return EntityId::fromString(bin2hex(random_bytes(16)));
    }

    public function expectedPublishedState(DateTimeImmutable $at): GiveawayState
    {
        $at = $at->setTimezone(new DateTimeZone('UTC'));
        if ($at < $this->startsAt) {
            return GiveawayState::Scheduled;
        }
        if ($at < $this->endsAt) {
            return GiveawayState::Open;
        }
        return GiveawayState::Closed;
    }

    private static function text(string $value, int $maxBytes, string $label, bool $allowEmpty): void
    {
        if (preg_match('//u', $value) !== 1
            || strlen($value) > $maxBytes
            || (!$allowEmpty && trim($value) === '')
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1
        ) {
            throw new InvalidArgumentException('Giveaway ' . $label . ' is invalid.');
        }
    }
}
