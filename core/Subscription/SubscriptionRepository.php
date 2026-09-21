<?php

declare(strict_types=1);

namespace Forwext\Core\Subscription;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Payment\PaymentAttemptState;

interface SubscriptionRepository
{
    /** @return list<SubscriptionPlan> */
    public function plans(bool $activeOnly=false,int $limit=200):array;
    public function plan(EntityId $planId,bool $forUpdate=false):?SubscriptionPlan;
    public function savePlan(SubscriptionPlan $plan,EntityId $actor):void;

    /** @return list<EntityId> */
    public function roleIds(EntityId $planId):array;
    /** @return list<string> */
    public function permissionKeys(EntityId $planId):array;
    /** @param list<EntityId> $roleIds */
    public function replaceRoles(EntityId $planId,array $roleIds):void;
    /** @param list<string> $permissionKeys */
    public function replacePermissions(EntityId $planId,array $permissionKeys):void;

    /** @return list<array{id:EntityId,name:string}> */
    public function eligibleRoles():array;
    /** @return list<string> */
    public function eligiblePermissions():array;
    public function roleEligible(EntityId $roleId):bool;
    public function permissionEligible(string $permissionKey):bool;

    public function userSubscription(EntityId $userId,EntityId $planId,bool $forUpdate=false):?UserSubscription;
    public function subscription(EntityId $subscriptionId,bool $forUpdate=false):?UserSubscription;
    /** @return list<UserSubscription> */
    public function subscriptionsForUser(EntityId $userId,int $limit=100):array;
    /** @return list<UserSubscription> */
    public function subscriptions(int $limit=200):array;
    public function saveSubscription(UserSubscription $subscription):void;
    /** @return list<UserSubscription> */
    public function dueForExpiry(DateTimeImmutable $at,int $limit=100):array;

    public function purchase(EntityId $purchaseId,bool $forUpdate=false):?SubscriptionPurchase;
    public function purchaseByIdempotency(
        EntityId $userId,EntityId $planId,string $providerKey,string $idempotencyKey
    ):?SubscriptionPurchase;
    public function purchaseByProviderReference(string $providerKey,string $providerReference):?SubscriptionPurchase;
    public function activePurchase(EntityId $userId,EntityId $planId,bool $forUpdate=false):?SubscriptionPurchase;
    public function insertPurchase(SubscriptionPurchase $purchase):void;
    public function savePurchase(SubscriptionPurchase $purchase):void;
    /** @return list<SubscriptionPurchase> */
    public function purchasesForUser(EntityId $userId,int $limit=100):array;

    public function webhookEventExists(string $providerKey,string $eventId):bool;
    public function insertWebhookEvent(
        string $providerKey,string $eventId,EntityId $purchaseId,PaymentAttemptState $state,
        string $payloadHash,DateTimeImmutable $occurredAt,DateTimeImmutable $receivedAt
    ):void;

    public function recordEvent(
        EntityId $subscriptionId,?EntityId $actor,string $action,SubscriptionState $fromState,SubscriptionState $toState,
        ?DateTimeImmutable $fromEndsAt,?DateTimeImmutable $toEndsAt,?EntityId $purchaseId,DateTimeImmutable $at
    ):void;
}
