<?php

declare(strict_types=1);

namespace Forwext\Core\Webhook;

use DateTimeImmutable;

interface WebhookRepository
{
    public function saveSubscription(WebhookSubscription $subscription): void;
    public function subscription(string $subscriptionId): ?WebhookSubscription;

    /** @return list<WebhookSubscription> */
    public function activeSubscriptionsForEvent(string $eventName): array;

    public function updateSecretRotation(
        string $subscriptionId,
        int $secretVersion,
        int $previousSecretVersion,
        DateTimeImmutable $previousSecretValidUntil,
        DateTimeImmutable $updatedAt,
    ): void;

    public function createDelivery(WebhookDelivery $delivery): void;
    public function delivery(string $deliveryId): ?WebhookDelivery;

    /** @return list<WebhookDelivery> */
    public function recentDeliveries(string $subscriptionId, int $limit = 100): array;

    /** @return list<WebhookDeliveryAttempt> */
    public function attempts(string $deliveryId): array;

    public function recordAttempt(
        string $deliveryId,
        int $attemptNumber,
        string $result,
        ?int $httpStatus,
        ?string $errorCode,
        bool $retryable,
        DateTimeImmutable $startedAt,
        DateTimeImmutable $finishedAt,
    ): void;

    public function markDelivered(string $deliveryId, int $attemptCount, DateTimeImmutable $at): void;

    public function scheduleRetry(
        string $deliveryId,
        int $attemptCount,
        DateTimeImmutable $nextAttemptAt,
        ?int $httpStatus,
        string $errorCode,
        DateTimeImmutable $updatedAt,
    ): void;

    public function markFailed(
        string $deliveryId,
        int $attemptCount,
        ?int $httpStatus,
        string $errorCode,
        DateTimeImmutable $updatedAt,
    ): void;
}
