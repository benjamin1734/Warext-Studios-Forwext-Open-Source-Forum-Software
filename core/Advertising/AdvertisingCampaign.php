<?php

declare(strict_types=1);

namespace Forwext\Core\Advertising;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class AdvertisingCampaign
{
    public DateTimeImmutable $createdAt;
    public DateTimeImmutable $updatedAt;
    public ?DateTimeImmutable $startsAt;
    public ?DateTimeImmutable $endsAt;

    public function __construct(
        public EntityId $campaignId,
        public string $key,
        public AdvertisingKind $kind,
        public string $name,
        public string $headline,
        public string $body,
        public ?string $destinationUrl,
        public string $placementKey,
        public bool $enabled,
        public int $priority,
        public ?int $frequencyCap,
        public ?int $frequencyWindowSeconds,
        public int $impressionValueMinor,
        public int $clickValueMinor,
        public string $currency,
        ?DateTimeImmutable $startsAt,
        ?DateTimeImmutable $endsAt,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ) {
        if (preg_match('/^[a-f0-9]{32}$/D', $this->campaignId->value()) !== 1) {
            throw new InvalidArgumentException('Advertising campaign id is invalid.');
        }
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $this->key) !== 1) {
            throw new InvalidArgumentException('Advertising campaign key is invalid.');
        }
        if (trim($this->name) === '' || strlen($this->name) > 120 || preg_match('//u', $this->name) !== 1) {
            throw new InvalidArgumentException('Advertising campaign name is invalid.');
        }
        if (trim($this->headline) === '' || strlen($this->headline) > 160 || preg_match('//u', $this->headline) !== 1) {
            throw new InvalidArgumentException('Advertising headline is invalid.');
        }
        if (strlen($this->body) > 4000 || preg_match('//u', $this->body) !== 1) {
            throw new InvalidArgumentException('Advertising body is invalid.');
        }
        if ($this->destinationUrl !== null && (strlen($this->destinationUrl) > 2048 || preg_match('/[\x00-\x20\x7F]/', $this->destinationUrl) === 1)) {
            throw new InvalidArgumentException('Advertising destination URL is invalid.');
        }
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $this->placementKey) !== 1) {
            throw new InvalidArgumentException('Advertising placement key is invalid.');
        }
        if ($this->priority < -32768 || $this->priority > 32767) {
            throw new InvalidArgumentException('Advertising priority is invalid.');
        }
        if (($this->frequencyCap === null) !== ($this->frequencyWindowSeconds === null)) {
            throw new InvalidArgumentException('Advertising frequency cap and window must be configured together.');
        }
        if ($this->frequencyCap !== null && ($this->frequencyCap < 1 || $this->frequencyCap > 100000)) {
            throw new InvalidArgumentException('Advertising frequency cap is invalid.');
        }
        if ($this->frequencyWindowSeconds !== null && ($this->frequencyWindowSeconds < 60 || $this->frequencyWindowSeconds > 2678400)) {
            throw new InvalidArgumentException('Advertising frequency window is invalid.');
        }
        if ($this->impressionValueMinor < 0 || $this->clickValueMinor < 0) {
            throw new InvalidArgumentException('Advertising event value cannot be negative.');
        }
        if (preg_match('/^[A-Z]{3}$/D', $this->currency) !== 1) {
            throw new InvalidArgumentException('Advertising currency is invalid.');
        }

        $utc = new DateTimeZone('UTC');
        $this->startsAt = $startsAt?->setTimezone($utc);
        $this->endsAt = $endsAt?->setTimezone($utc);
        $this->createdAt = $createdAt->setTimezone($utc);
        $this->updatedAt = $updatedAt->setTimezone($utc);

        if ($this->endsAt !== null && $this->startsAt !== null && $this->endsAt <= $this->startsAt) {
            throw new InvalidArgumentException('Advertising campaign end must be after start.');
        }
        if ($this->updatedAt < $this->createdAt) {
            throw new InvalidArgumentException('Advertising campaign timestamps are invalid.');
        }
    }

    public static function generateId():EntityId
    {
        return EntityId::fromString(bin2hex(random_bytes(16)));
    }

    public function activeAt(DateTimeImmutable $at):bool
    {
        $at = $at->setTimezone(new DateTimeZone('UTC'));
        return $this->enabled
            && ($this->startsAt === null || $this->startsAt <= $at)
            && ($this->endsAt === null || $this->endsAt > $at);
    }
}
