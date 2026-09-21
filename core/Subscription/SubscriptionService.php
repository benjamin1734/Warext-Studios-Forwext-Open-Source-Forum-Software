<?php

declare(strict_types=1);

namespace Forwext\Core\Subscription;

use DateInterval;
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
use Forwext\Core\Payment\PaymentAttemptState;
use Forwext\Core\Payment\PaymentCreateRequest;
use Forwext\Core\Payment\PaymentProviderException;
use Forwext\Core\Payment\PaymentProviderRegistry;
use Forwext\Core\Payment\PaymentWebhookRequest;
use Forwext\Core\Payment\PaymentWebhookVerificationException;
use InvalidArgumentException;
use Throwable;

final readonly class SubscriptionService
{
    public function __construct(
        private TransactionalQueryExecutor $database,
        private SubscriptionRepository $subscriptions,
        private PaymentProviderRegistry $providers,
        private PermissionAuthorizer $authorizer,
        private AuditRecorder $audit,
    ){}

    /** @return list<string> */
    public function providerKeys():array
    {
        return $this->providers->keys();
    }

    /** @return array{plans:list<SubscriptionPlan>,subscriptions:list<UserSubscription>,purchases:list<SubscriptionPurchase>,providers:list<string>,can_purchase:bool} */
    public function accountSnapshot(EntityId $actor,DateTimeImmutable $now):array
    {
        $this->require($actor,'subscription.view');
        $this->expireUserSubscriptions($actor,$now);
        return [
            'plans'=>$this->subscriptions->plans(true),
            'subscriptions'=>$this->subscriptions->subscriptionsForUser($actor),
            'purchases'=>$this->subscriptions->purchasesForUser($actor),
            'providers'=>$this->providers->keys(),
            'can_purchase'=>$this->allows($actor,'subscription.purchase'),
        ];
    }

    /** @return array{plans:list<SubscriptionPlan>,subscriptions:list<UserSubscription>,roles:list<array{id:EntityId,name:string}>,permissions:list<string>,providers:list<string>} */
    public function managementSnapshot(EntityId $actor,DateTimeImmutable $now):array
    {
        $this->require($actor,'subscription.manage_all');
        $this->expireDue(200,$now);
        return [
            'plans'=>$this->subscriptions->plans(false),
            'subscriptions'=>$this->subscriptions->subscriptions(300),
            'roles'=>$this->subscriptions->eligibleRoles(),
            'permissions'=>array_values(array_filter(
                $this->subscriptions->eligiblePermissions(),
                fn(string $permission):bool=>$this->permissionSafeForUpgrade($permission)
            )),
            'providers'=>$this->providers->keys(),
        ];
    }

    /** @param list<EntityId> $roleIds @param list<string> $permissionKeys */
    public function savePlan(
        EntityId $actor,
        SubscriptionPlan $plan,
        array $roleIds,
        array $permissionKeys,
        DateTimeImmutable $now,
        ?AuditRequestId $requestId=null,
    ):void{
        $this->require($actor,'subscription.manage_all');
        foreach($roleIds as $roleId){
            if(!$roleId instanceof EntityId||!$this->subscriptions->roleEligible($roleId)){
                throw new InvalidArgumentException('Subscription plan role is not eligible.');
            }
        }
        foreach($permissionKeys as $permissionKey){
            if(!is_string($permissionKey)||!$this->permissionSafeForUpgrade($permissionKey)
                ||!$this->subscriptions->permissionEligible($permissionKey)
            ){
                throw new InvalidArgumentException('Subscription plan permission is not eligible.');
            }
        }

        $before=$this->subscriptions->plan($plan->planId);
        $at=self::utc($now);
        $event=new AuditEvent(
            AuditEvent::generateId(),AuditScope::Administration,$actor,
            AuditAction::fromString($before===null?'subscription.plan.create':'subscription.plan.update'),
            'subscription.plan',$plan->planId->value(),null,
            $before===null?'subscription.plan.create':'subscription.plan.update',
            $requestId??AuditRequestId::generate(),
            $before===null?[]:self::planSnapshot($before),
            self::planSnapshot($plan)+['roles'=>count($roleIds),'permissions'=>count($permissionKeys)],
            $at
        );
        $this->audit->mutate($event,function()use($plan,$actor,$roleIds,$permissionKeys):void{
            $this->subscriptions->savePlan($plan,$actor);
            $this->subscriptions->replaceRoles($plan->planId,$roleIds);
            $this->subscriptions->replacePermissions($plan->planId,array_values(array_unique($permissionKeys)));
        });
    }

    public function manualGrant(
        EntityId $actor,
        EntityId $userId,
        EntityId $planId,
        DateTimeImmutable $now,
        ?AuditRequestId $requestId=null,
    ):UserSubscription{
        $this->require($actor,'subscription.manage_all');
        $plan=$this->subscriptions->plan($planId)
            ??throw new InvalidArgumentException('Subscription plan was not found.');
        $at=self::utc($now);
        $after=$this->database->transaction(
            fn():UserSubscription=>$this->activate(
                $userId,$plan->durationDays,$planId,$at,null,$actor,'subscription.manual_grant'
            )
        );

        $this->audit->append(new AuditEvent(
            AuditEvent::generateId(),AuditScope::Administration,$actor,
            AuditAction::fromString('subscription.grant'),'subscription.assignment',$after->subscriptionId->value(),
            null,'subscription.grant',$requestId??AuditRequestId::generate(),[],
            ['user_id'=>$userId->value(),'plan_id'=>$planId->value(),'ends_at'=>$after->endsAt?->format(DATE_ATOM)],
            $at
        ));
        return $after;
    }

    public function revoke(
        EntityId $actor,
        EntityId $subscriptionId,
        DateTimeImmutable $now,
        ?AuditRequestId $requestId=null,
    ):UserSubscription{
        $this->require($actor,'subscription.manage_all');
        $at=self::utc($now);
        $before=$this->subscriptions->subscription($subscriptionId,true)
            ??throw new InvalidArgumentException('Subscription was not found.');
        if($before->state===SubscriptionState::Revoked)return $before;
        $after=new UserSubscription(
            $before->subscriptionId,$before->userId,$before->planId,SubscriptionState::Revoked,
            $before->startsAt,$before->endsAt,$before->createdAt,$at
        );
        $event=new AuditEvent(
            AuditEvent::generateId(),AuditScope::Administration,$actor,
            AuditAction::fromString('subscription.revoke'),'subscription.assignment',$subscriptionId->value(),
            null,'subscription.revoke',$requestId??AuditRequestId::generate(),
            ['state'=>$before->state->value],['state'=>$after->state->value],$at
        );
        return $this->audit->mutate($event,function()use($before,$after,$actor,$at):UserSubscription{
            $this->subscriptions->saveSubscription($after);
            $this->subscriptions->recordEvent(
                $after->subscriptionId,$actor,'subscription.revoke',$before->state,$after->state,
                $before->endsAt,$after->endsAt,null,$at
            );
            return $after;
        });
    }

    public function expireDue(int $limit,DateTimeImmutable $now):int
    {
        if($limit<1||$limit>500)throw new InvalidArgumentException('Subscription expiry limit is invalid.');
        $at=self::utc($now);
        $count=0;
        foreach($this->subscriptions->dueForExpiry($at,$limit) as $candidate){
            $changed=$this->database->transaction(function()use($candidate,$at):bool{
                $current=$this->subscriptions->subscription($candidate->subscriptionId,true);
                if($current===null||$current->state!==SubscriptionState::Active
                    ||$current->endsAt===null||$current->endsAt>$at
                )return false;
                $expired=new UserSubscription(
                    $current->subscriptionId,$current->userId,$current->planId,SubscriptionState::Expired,
                    $current->startsAt,$current->endsAt,$current->createdAt,$at
                );
                $this->subscriptions->saveSubscription($expired);
                $this->subscriptions->recordEvent(
                    $expired->subscriptionId,null,'subscription.expire',$current->state,$expired->state,
                    $current->endsAt,$expired->endsAt,null,$at
                );
                return true;
            });
            if($changed)++$count;
        }
        return $count;
    }

    public function initiatePurchase(
        EntityId $actor,
        EntityId $planId,
        string $providerKey,
        string $idempotencyKey,
        string $returnPath,
        string $cancelPath,
        DateTimeImmutable $now,
    ):SubscriptionPurchase{
        $this->require($actor,'subscription.purchase');
        self::assertIdempotencyKey($idempotencyKey);
        $provider=$this->providers->require($providerKey);
        $at=self::utc($now);

        $purchase=$this->database->transaction(function()use(
            $actor,$planId,$providerKey,$idempotencyKey,$at
        ):SubscriptionPurchase{
            $existing=$this->subscriptions->purchaseByIdempotency($actor,$planId,$providerKey,$idempotencyKey);
            if($existing!==null)return $existing;

            $plan=$this->subscriptions->plan($planId,true)
                ??throw new InvalidArgumentException('Subscription plan was not found.');
            if(!$plan->active||$plan->priceMinor<1){
                throw new InvalidArgumentException('Subscription plan is not available for paid purchase.');
            }

            $created=new SubscriptionPurchase(
                SubscriptionPurchase::generateId(),$actor,$plan->planId,$providerKey,$idempotencyKey,
                $plan->priceMinor,$plan->currency,$plan->durationDays,PaymentAttemptState::Pending,
                null,null,null,$at,$at
            );
            $this->subscriptions->insertPurchase($created);
            return $created;
        });

        if($purchase->state===PaymentAttemptState::Paid){
            return $this->activatePaidPurchase($purchase,$at);
        }
        if($purchase->providerReference!==null||$purchase->state!==PaymentAttemptState::Pending){
            return $purchase;
        }

        try{
            $result=$provider->create(new PaymentCreateRequest(
                $purchase->purchaseId,$purchase->purchaseId,$purchase->userId,$purchase->amountMinor,
                $purchase->currency,$purchase->idempotencyKey,$returnPath,$cancelPath
            ));
        }catch(Throwable $exception){
            throw new PaymentProviderException('Subscription payment provider initiation failed.',previous:$exception);
        }
        if($result->state===PaymentAttemptState::Refunded){
            throw new PaymentProviderException('Subscription payment provider returned an invalid initiation state.');
        }

        $final=$this->database->transaction(function()use($purchase,$result,$at):SubscriptionPurchase{
            $current=$this->subscriptions->purchase($purchase->purchaseId,true)
                ??throw new InvalidArgumentException('Subscription purchase was not found.');
            if($current->providerReference!==null&&$result->providerReference!==null
                &&!hash_equals($current->providerReference,$result->providerReference)
            ){
                throw new InvalidArgumentException('Subscription payment provider reference changed.');
            }
            if($current->state!==PaymentAttemptState::Pending)return $current;
            $updated=$this->withPurchaseState(
                $current,$result->state,$current->providerReference??$result->providerReference,
                $result->checkoutUrl,$result->errorCode,$at,$current->activatedAt
            );
            $this->subscriptions->savePurchase($updated);
            return $updated;
        });

        return $final->state===PaymentAttemptState::Paid?$this->activatePaidPurchase($final,$at):$final;
    }

    public function handleWebhook(string $providerKey,PaymentWebhookRequest $request):SubscriptionPurchase
    {
        $provider=$this->providers->require($providerKey);
        try{
            $event=$provider->verifyWebhook($request);
        }catch(PaymentWebhookVerificationException $exception){
            throw $exception;
        }catch(Throwable $exception){
            throw new PaymentWebhookVerificationException('Subscription payment webhook verification failed.',previous:$exception);
        }

        $purchase=$event->attemptId!==null
            ?$this->subscriptions->purchase($event->attemptId)
            :($event->providerReference===null?null:$this->subscriptions->purchaseByProviderReference($providerKey,$event->providerReference));
        if($purchase===null||!hash_equals($purchase->providerKey,$providerKey)){
            throw new InvalidArgumentException('Subscription webhook does not reference a known purchase.');
        }
        if($event->providerReference!==null&&$purchase->providerReference!==null
            &&!hash_equals($purchase->providerReference,$event->providerReference)
        ){
            throw new InvalidArgumentException('Subscription webhook provider reference mismatch.');
        }
        if($event->amountMinor!==null&&$event->amountMinor!==$purchase->amountMinor){
            throw new InvalidArgumentException('Subscription webhook amount mismatch.');
        }
        if($event->currency!==null&&!hash_equals($purchase->currency,$event->currency)){
            throw new InvalidArgumentException('Subscription webhook currency mismatch.');
        }

        $final=$this->database->transaction(function()use($providerKey,$request,$event,$purchase):SubscriptionPurchase{
            $current=$this->subscriptions->purchase($purchase->purchaseId,true)
                ??throw new InvalidArgumentException('Subscription purchase was not found.');
            if($this->subscriptions->webhookEventExists($providerKey,$event->eventId))return $current;
            $this->subscriptions->insertWebhookEvent(
                $providerKey,$event->eventId,$current->purchaseId,$event->state,
                hash('sha256',$request->rawBody),$event->occurredAt,$request->receivedAt
            );
            if(!self::shouldApplyState($current->state,$event->state))return $current;
            $reference=$current->providerReference??$event->providerReference;
            $updated=$this->withPurchaseState(
                $current,$event->state,$reference,
                $event->state===PaymentAttemptState::RequiresAction?$current->checkoutUrl:null,
                null,$request->receivedAt,$current->activatedAt
            );
            $this->subscriptions->savePurchase($updated);
            return $updated;
        });

        return $final->state===PaymentAttemptState::Paid
            ?$this->activatePaidPurchase($final,$request->receivedAt)
            :$final;
    }

    private function activatePaidPurchase(SubscriptionPurchase $purchase,DateTimeImmutable $at):SubscriptionPurchase
    {
        return $this->database->transaction(function()use($purchase,$at):SubscriptionPurchase{
            $current=$this->subscriptions->purchase($purchase->purchaseId,true)
                ??throw new InvalidArgumentException('Subscription purchase was not found.');
            if($current->state!==PaymentAttemptState::Paid)return $current;
            if($current->activatedAt!==null)return $current;

            $this->activate(
                $current->userId,$current->durationDays,$current->planId,$at,$current->purchaseId,null,
                'subscription.payment_activate'
            );
            $activated=$this->withPurchaseState(
                $current,$current->state,$current->providerReference,$current->checkoutUrl,$current->errorCode,
                $at,$at
            );
            $this->subscriptions->savePurchase($activated);
            return $activated;
        });
    }

    private function activate(
        EntityId $userId,
        ?int $durationDays,
        EntityId $planId,
        DateTimeImmutable $at,
        ?EntityId $purchaseId,
        ?EntityId $actor,
        string $action,
    ):UserSubscription{
        $current=$this->subscriptions->userSubscription($userId,$planId,true);
        $base=$at;
        if($current!==null&&$current->state===SubscriptionState::Active
            &&$current->endsAt!==null&&$current->endsAt>$at
        ){
            $base=$current->endsAt;
        }

        if($current!==null&&$current->state===SubscriptionState::Active&&$current->endsAt===null){
            $endsAt=null;
        }elseif($durationDays===null){
            $endsAt=null;
        }else{
            $endsAt=$base->add(new DateInterval('P'.$durationDays.'D'));
        }

        $beforeState=$current?->state??SubscriptionState::Expired;
        $beforeEnds=$current?->endsAt;
        $subscription=new UserSubscription(
            $current?->subscriptionId??UserSubscription::generateId(),$userId,$planId,SubscriptionState::Active,
            $current?->startsAt??$at,$endsAt,$current?->createdAt??$at,$at
        );
        $this->subscriptions->saveSubscription($subscription);
        $this->subscriptions->recordEvent(
            $subscription->subscriptionId,$actor,$current===null?$action:$action.'.renew',
            $beforeState,$subscription->state,$beforeEnds,$subscription->endsAt,$purchaseId,$at
        );
        return $subscription;
    }

    private function expireUserSubscriptions(EntityId $userId,DateTimeImmutable $now):void
    {
        $at=self::utc($now);
        foreach($this->subscriptions->subscriptionsForUser($userId,100) as $subscription){
            if($subscription->state!==SubscriptionState::Active||$subscription->endsAt===null||$subscription->endsAt>$at)continue;
            $this->database->transaction(function()use($subscription,$at):void{
                $current=$this->subscriptions->subscription($subscription->subscriptionId,true);
                if($current===null||$current->state!==SubscriptionState::Active
                    ||$current->endsAt===null||$current->endsAt>$at
                )return;
                $expired=new UserSubscription(
                    $current->subscriptionId,$current->userId,$current->planId,SubscriptionState::Expired,
                    $current->startsAt,$current->endsAt,$current->createdAt,$at
                );
                $this->subscriptions->saveSubscription($expired);
                $this->subscriptions->recordEvent(
                    $expired->subscriptionId,null,'subscription.expire',$current->state,$expired->state,
                    $current->endsAt,$expired->endsAt,null,$at
                );
            });
        }
    }

    private function withPurchaseState(
        SubscriptionPurchase $purchase,
        PaymentAttemptState $state,
        ?string $reference,
        ?string $checkoutUrl,
        ?string $errorCode,
        DateTimeImmutable $at,
        ?DateTimeImmutable $activatedAt,
    ):SubscriptionPurchase{
        return new SubscriptionPurchase(
            $purchase->purchaseId,$purchase->userId,$purchase->planId,$purchase->providerKey,$purchase->idempotencyKey,
            $purchase->amountMinor,$purchase->currency,$purchase->durationDays,$state,$reference,$checkoutUrl,$errorCode,
            $purchase->createdAt,$at,$activatedAt
        );
    }

    private function permissionSafeForUpgrade(string $permission):bool
    {
        if(preg_match('/^[a-z][a-z0-9_.-]{1,95}$/D',$permission)!==1)return false;
        if(preg_match('/^(?:acp|moderation|audit|payment)\./D',$permission)===1)return false;
        if(preg_match('/(?:^|\.)(?:manage_all|manage|moderate|override|refund|export)$/D',$permission)===1)return false;
        return !in_array($permission,[
            'subscription.manage_all','reward.manage','promotion.manage','ai.manage',
            'support.ticket.view_all','support.ticket.reply_all','support.ticket.assign','support.ticket.escalate',
            'bug.report.view_all','bug.report.manage','bug.report.assign',
        ],true);
    }

    private static function shouldApplyState(PaymentAttemptState $current,PaymentAttemptState $target):bool
    {
        if($current===$target||$current===PaymentAttemptState::Refunded)return false;
        if($target===PaymentAttemptState::Refunded)return $current===PaymentAttemptState::Paid;
        if($target===PaymentAttemptState::Paid)return true;
        if($current===PaymentAttemptState::Paid)return false;
        return match($target){
            PaymentAttemptState::Authorized=>in_array($current,[PaymentAttemptState::Pending,PaymentAttemptState::RequiresAction],true),
            PaymentAttemptState::RequiresAction=>$current===PaymentAttemptState::Pending,
            PaymentAttemptState::Failed,PaymentAttemptState::Cancelled=>in_array(
                $current,[PaymentAttemptState::Pending,PaymentAttemptState::RequiresAction,PaymentAttemptState::Authorized],true
            ),
            PaymentAttemptState::Pending=>false,
            default=>false,
        };
    }

    private function require(EntityId $actor,string $permission):void
    {
        $decision=$this->authorizer->resolve($actor,PermissionKey::fromString($permission));
        if(!$decision->isAllowed())throw new PermissionDeniedException($decision);
    }

    private function allows(EntityId $actor,string $permission):bool
    {
        return $this->authorizer->allows($actor,PermissionKey::fromString($permission));
    }

    private static function assertIdempotencyKey(string $key):void
    {
        if(preg_match('/^[a-f0-9]{32}$/D',$key)!==1){
            throw new InvalidArgumentException('Subscription payment idempotency key is invalid.');
        }
    }

    /** @return array<string,scalar|null> */
    private static function planSnapshot(SubscriptionPlan $plan):array
    {
        return [
            'key'=>$plan->key,'active'=>$plan->active,'price_minor'=>$plan->priceMinor,'currency'=>$plan->currency,
            'duration_days'=>$plan->durationDays,'sort_order'=>$plan->sortOrder,
        ];
    }

    private static function utc(DateTimeImmutable $at):DateTimeImmutable
    {
        return $at->setTimezone(new DateTimeZone('UTC'));
    }
}
