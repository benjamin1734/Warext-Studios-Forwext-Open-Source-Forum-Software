<?php

declare(strict_types=1);

namespace Forwext\Core\Webhook;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class WebhookDelivery
{
    public DateTimeImmutable $createdAt;
    public DateTimeImmutable $updatedAt;
    public ?DateTimeImmutable $nextAttemptAt;
    public ?DateTimeImmutable $deliveredAt;

    public function __construct(
        public string $id,
        public string $subscriptionId,
        public string $eventName,
        public string $bodyJson,
        public bool $test,
        public WebhookDeliveryStatus $status,
        public int $attemptCount,
        public int $maxAttempts,
        ?DateTimeImmutable $nextAttemptAt,
        public ?int $lastHttpStatus,
        public ?string $lastErrorCode,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
        ?DateTimeImmutable $deliveredAt = null,
    ) {
        if (preg_match('/^[a-f0-9]{32}$/D', $this->id) !== 1
            || preg_match('/^[a-f0-9]{32}$/D', $this->subscriptionId) !== 1
            || preg_match('/^[A-Za-z][A-Za-z0-9._:-]{0,190}$/D', $this->eventName) !== 1
            || $this->bodyJson === '' || strlen($this->bodyJson) > 1_048_576
            || $this->maxAttempts < 1 || $this->maxAttempts > 20
            || $this->attemptCount < 0 || $this->attemptCount > $this->maxAttempts
            || ($this->lastHttpStatus !== null && ($this->lastHttpStatus < 100 || $this->lastHttpStatus > 599))
            || ($this->lastErrorCode !== null && preg_match('/^[a-z0-9._-]{1,64}$/D', $this->lastErrorCode) !== 1)
        ) {
            throw new InvalidArgumentException('Webhook delivery metadata is invalid.');
        }

        $utc = new DateTimeZone('UTC');
        $this->nextAttemptAt = $nextAttemptAt?->setTimezone($utc);
        $this->createdAt = $createdAt->setTimezone($utc);
        $this->updatedAt = $updatedAt->setTimezone($utc);
        $this->deliveredAt = $deliveredAt?->setTimezone($utc);
    }
}
