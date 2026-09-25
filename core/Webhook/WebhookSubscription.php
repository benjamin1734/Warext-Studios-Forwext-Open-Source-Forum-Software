<?php

declare(strict_types=1);

namespace Forwext\Core\Webhook;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class WebhookSubscription
{
    public DateTimeImmutable $createdAt;
    public DateTimeImmutable $updatedAt;
    public ?DateTimeImmutable $previousSecretValidUntil;

    public function __construct(
        public string $id,
        public string $eventName,
        public string $destinationUrl,
        public bool $active,
        public int $secretVersion,
        public ?int $previousSecretVersion,
        ?DateTimeImmutable $previousSecretValidUntil,
        public int $maxAttempts,
        public ?string $createdByUserId,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ) {
        if (preg_match('/^[a-f0-9]{32}$/D', $this->id) !== 1
            || preg_match('/^[A-Za-z][A-Za-z0-9._:-]{0,190}$/D', $this->eventName) !== 1
            || $this->destinationUrl === '' || strlen($this->destinationUrl) > 2048
            || $this->secretVersion < 1 || $this->secretVersion > 65535
            || ($this->previousSecretVersion !== null
                && ($this->previousSecretVersion < 1 || $this->previousSecretVersion >= $this->secretVersion))
            || $this->maxAttempts < 1 || $this->maxAttempts > 20
        ) {
            throw new InvalidArgumentException('Webhook subscription metadata is invalid.');
        }
        if ($this->createdByUserId !== null && preg_match('/^[a-f0-9]{32}$/D', $this->createdByUserId) !== 1) {
            throw new InvalidArgumentException('Webhook subscription actor id is invalid.');
        }

        $utc = new DateTimeZone('UTC');
        $this->previousSecretValidUntil = $previousSecretValidUntil?->setTimezone($utc);
        $this->createdAt = $createdAt->setTimezone($utc);
        $this->updatedAt = $updatedAt->setTimezone($utc);
    }
}
