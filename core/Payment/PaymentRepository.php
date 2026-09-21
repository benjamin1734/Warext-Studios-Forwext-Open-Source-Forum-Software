<?php

declare(strict_types=1);

namespace Forwext\Core\Payment;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

interface PaymentRepository
{
    public function attempt(EntityId $attemptId,bool $forUpdate=false):?PaymentAttempt;
    public function attemptByIdempotency(EntityId $orderId,string $providerKey,string $idempotencyKey):?PaymentAttempt;
    public function attemptByProviderReference(string $providerKey,string $providerReference):?PaymentAttempt;
    public function insertAttempt(PaymentAttempt $attempt):void;
    public function saveAttempt(PaymentAttempt $attempt):void;

    public function webhookEventExists(string $providerKey,string $eventId):bool;
    public function insertWebhookEvent(
        string $providerKey,string $eventId,EntityId $attemptId,PaymentAttemptState $state,
        string $payloadHash,DateTimeImmutable $occurredAt,DateTimeImmutable $receivedAt
    ):void;

    public function refund(EntityId $refundId,bool $forUpdate=false):?PaymentRefund;
    public function refundByIdempotency(EntityId $attemptId,string $idempotencyKey):?PaymentRefund;
    public function refundByProviderReference(EntityId $attemptId,string $providerRefundReference):?PaymentRefund;
    public function insertRefund(PaymentRefund $refund):void;
    public function saveRefund(PaymentRefund $refund):void;

    /** @return list<PaymentAttempt> */
    public function attempts(int $limit=100):array;

    /** @return list<PaymentRefund> */
    public function refundsForAttempt(EntityId $attemptId):array;
}
