<?php

declare(strict_types=1);

namespace Forwext\Core\Subscription;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Payment\PaymentAttemptState;
use InvalidArgumentException;

final readonly class DatabaseSubscriptionRepository implements SubscriptionRepository
{
    public function __construct(private TransactionalQueryExecutor $database){}

    public function plans(bool $activeOnly=false,int $limit=200):array
    {
        if($limit<1||$limit>500)throw new InvalidArgumentException('Subscription plan list limit is invalid.');
        $rows=$this->database->fetchAll(new CompiledQuery(
            'SELECT * FROM forwext_subscription_plans'.($activeOnly?' WHERE active=1':'')
            .' ORDER BY sort_order,name,plan_id LIMIT '.$limit
        ));
        return array_map($this->hydratePlan(...),$rows);
    }

    public function plan(EntityId $planId,bool $forUpdate=false):?SubscriptionPlan
    {
        $row=$this->database->fetchOne(new CompiledQuery(
            'SELECT * FROM forwext_subscription_plans WHERE plan_id=:id LIMIT 1'.($forUpdate?' FOR UPDATE':''),
            ['id'=>$planId->value()]
        ));
        return $row===null?null:$this->hydratePlan($row);
    }

    public function savePlan(SubscriptionPlan $plan,EntityId $actor):void
    {
        UserId::assert($actor);
        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_subscription_plans '
            . '(plan_id,plan_key,name,description,active,price_minor,currency,duration_days,sort_order,created_by_user_id,updated_by_user_id,created_at_utc,updated_at_utc) '
            . 'VALUES (:id,:key,:name,:description,:active,:price,:currency,:duration,:sort_order,:actor,:actor,:created,:updated) '
            . 'ON DUPLICATE KEY UPDATE plan_key=VALUES(plan_key),name=VALUES(name),description=VALUES(description),'
            . 'active=VALUES(active),price_minor=VALUES(price_minor),currency=VALUES(currency),duration_days=VALUES(duration_days),'
            . 'sort_order=VALUES(sort_order),updated_by_user_id=VALUES(updated_by_user_id),updated_at_utc=VALUES(updated_at_utc)',
            [
                'id'=>$plan->planId->value(),'key'=>$plan->key,'name'=>$plan->name,'description'=>$plan->description,
                'active'=>$plan->active,'price'=>$plan->priceMinor,'currency'=>$plan->currency,
                'duration'=>$plan->durationDays,'sort_order'=>$plan->sortOrder,'actor'=>$actor->value(),
                'created'=>self::format($plan->createdAt),'updated'=>self::format($plan->updatedAt),
            ]
        ));
    }

    public function roleIds(EntityId $planId):array
    {
        $rows=$this->database->fetchAll(new CompiledQuery(
            'SELECT role_id FROM forwext_subscription_plan_roles WHERE plan_id=:plan ORDER BY role_id',
            ['plan'=>$planId->value()]
        ));
        return array_map(static fn(array $row):EntityId=>EntityId::fromString((string)$row['role_id']),$rows);
    }

    public function permissionKeys(EntityId $planId):array
    {
        $rows=$this->database->fetchAll(new CompiledQuery(
            'SELECT permission_key FROM forwext_subscription_plan_permissions WHERE plan_id=:plan ORDER BY permission_key',
            ['plan'=>$planId->value()]
        ));
        return array_map(static fn(array $row):string=>(string)$row['permission_key'],$rows);
    }

    public function replaceRoles(EntityId $planId,array $roleIds):void
    {
        $this->database->execute(new CompiledQuery(
            'DELETE FROM forwext_subscription_plan_roles WHERE plan_id=:plan',['plan'=>$planId->value()]
        ));
        foreach($roleIds as $roleId){
            if(!$roleId instanceof EntityId)throw new InvalidArgumentException('Subscription role binding is invalid.');
            $this->database->execute(new CompiledQuery(
                'INSERT INTO forwext_subscription_plan_roles(plan_id,role_id) VALUES (:plan,:role)',
                ['plan'=>$planId->value(),'role'=>$roleId->value()]
            ));
        }
    }

    public function replacePermissions(EntityId $planId,array $permissionKeys):void
    {
        $this->database->execute(new CompiledQuery(
            'DELETE FROM forwext_subscription_plan_permissions WHERE plan_id=:plan',['plan'=>$planId->value()]
        ));
        foreach($permissionKeys as $permissionKey){
            if(!is_string($permissionKey))throw new InvalidArgumentException('Subscription permission binding is invalid.');
            $this->database->execute(new CompiledQuery(
                'INSERT INTO forwext_subscription_plan_permissions(plan_id,permission_key) VALUES (:plan,:permission)',
                ['plan'=>$planId->value(),'permission'=>$permissionKey]
            ));
        }
    }

    public function eligibleRoles():array
    {
        $rows=$this->database->fetchAll(new CompiledQuery(
            "SELECT role_id,name FROM forwext_roles WHERE kind='custom' AND is_protected=0 ORDER BY priority DESC,name,role_id"
        ));
        return array_map(static fn(array $row):array=>[
            'id'=>EntityId::fromString((string)$row['role_id']),'name'=>(string)$row['name'],
        ],$rows);
    }

    public function eligiblePermissions():array
    {
        $rows=$this->database->fetchAll(new CompiledQuery(
            "SELECT permission_key FROM forwext_permissions WHERE value_type='flag' "
            . "AND permission_key<>'subscription.manage_all' ORDER BY permission_key"
        ));
        return array_map(static fn(array $row):string=>(string)$row['permission_key'],$rows);
    }

    public function roleEligible(EntityId $roleId):bool
    {
        return (int)$this->database->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_roles WHERE role_id=:role AND kind='custom' AND is_protected=0",
            ['role'=>$roleId->value()]
        ))===1;
    }

    public function permissionEligible(string $permissionKey):bool
    {
        return (int)$this->database->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permissions WHERE permission_key=:permission AND value_type='flag' "
            . "AND permission_key<>'subscription.manage_all'",
            ['permission'=>$permissionKey]
        ))===1;
    }

    public function userSubscription(EntityId $userId,EntityId $planId,bool $forUpdate=false):?UserSubscription
    {
        UserId::assert($userId);
        $row=$this->database->fetchOne(new CompiledQuery(
            'SELECT * FROM forwext_user_subscriptions WHERE user_id=:user AND plan_id=:plan LIMIT 1'
            .($forUpdate?' FOR UPDATE':''),
            ['user'=>$userId->value(),'plan'=>$planId->value()]
        ));
        return $row===null?null:$this->hydrateSubscription($row);
    }

    public function subscription(EntityId $subscriptionId,bool $forUpdate=false):?UserSubscription
    {
        $row=$this->database->fetchOne(new CompiledQuery(
            'SELECT * FROM forwext_user_subscriptions WHERE subscription_id=:id LIMIT 1'.($forUpdate?' FOR UPDATE':''),
            ['id'=>$subscriptionId->value()]
        ));
        return $row===null?null:$this->hydrateSubscription($row);
    }

    public function subscriptionsForUser(EntityId $userId,int $limit=100):array
    {
        UserId::assert($userId);
        if($limit<1||$limit>500)throw new InvalidArgumentException('Subscription list limit is invalid.');
        $rows=$this->database->fetchAll(new CompiledQuery(
            'SELECT * FROM forwext_user_subscriptions WHERE user_id=:user ORDER BY created_at_utc DESC,subscription_id DESC LIMIT '.$limit,
            ['user'=>$userId->value()]
        ));
        return array_map($this->hydrateSubscription(...),$rows);
    }

    public function subscriptions(int $limit=200):array
    {
        if($limit<1||$limit>500)throw new InvalidArgumentException('Subscription management list limit is invalid.');
        $rows=$this->database->fetchAll(new CompiledQuery(
            'SELECT * FROM forwext_user_subscriptions ORDER BY updated_at_utc DESC,subscription_id DESC LIMIT '.$limit
        ));
        return array_map($this->hydrateSubscription(...),$rows);
    }

    public function saveSubscription(UserSubscription $subscription):void
    {
        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_user_subscriptions '
            . '(subscription_id,user_id,plan_id,state,starts_at_utc,ends_at_utc,created_at_utc,updated_at_utc) '
            . 'VALUES (:id,:user,:plan,:state,:starts,:ends,:created,:updated) '
            . 'ON DUPLICATE KEY UPDATE state=VALUES(state),starts_at_utc=VALUES(starts_at_utc),'
            . 'ends_at_utc=VALUES(ends_at_utc),updated_at_utc=VALUES(updated_at_utc)',
            [
                'id'=>$subscription->subscriptionId->value(),'user'=>$subscription->userId->value(),
                'plan'=>$subscription->planId->value(),'state'=>$subscription->state->value,
                'starts'=>self::format($subscription->startsAt),'ends'=>$subscription->endsAt===null?null:self::format($subscription->endsAt),
                'created'=>self::format($subscription->createdAt),'updated'=>self::format($subscription->updatedAt),
            ]
        ));
    }

    public function dueForExpiry(DateTimeImmutable $at,int $limit=100):array
    {
        if($limit<1||$limit>500)throw new InvalidArgumentException('Subscription expiry limit is invalid.');
        $rows=$this->database->fetchAll(new CompiledQuery(
            "SELECT * FROM forwext_user_subscriptions WHERE state='active' AND ends_at_utc IS NOT NULL "
            . 'AND ends_at_utc<=:at ORDER BY ends_at_utc,subscription_id LIMIT '.$limit,
            ['at'=>self::format($at)]
        ));
        return array_map($this->hydrateSubscription(...),$rows);
    }

    public function purchase(EntityId $purchaseId,bool $forUpdate=false):?SubscriptionPurchase
    {
        $row=$this->database->fetchOne(new CompiledQuery(
            'SELECT * FROM forwext_subscription_purchases WHERE purchase_id=:id LIMIT 1'.($forUpdate?' FOR UPDATE':''),
            ['id'=>$purchaseId->value()]
        ));
        return $row===null?null:$this->hydratePurchase($row);
    }

    public function purchaseByIdempotency(
        EntityId $userId,EntityId $planId,string $providerKey,string $idempotencyKey
    ):?SubscriptionPurchase{
        UserId::assert($userId);
        $row=$this->database->fetchOne(new CompiledQuery(
            'SELECT * FROM forwext_subscription_purchases WHERE user_id=:user AND plan_id=:plan '
            . 'AND provider_key=:provider AND idempotency_key=:idempotency LIMIT 1',
            ['user'=>$userId->value(),'plan'=>$planId->value(),'provider'=>$providerKey,'idempotency'=>$idempotencyKey]
        ));
        return $row===null?null:$this->hydratePurchase($row);
    }

    public function purchaseByProviderReference(string $providerKey,string $providerReference):?SubscriptionPurchase
    {
        $row=$this->database->fetchOne(new CompiledQuery(
            'SELECT * FROM forwext_subscription_purchases WHERE provider_key=:provider AND provider_reference=:reference LIMIT 1',
            ['provider'=>$providerKey,'reference'=>$providerReference]
        ));
        return $row===null?null:$this->hydratePurchase($row);
    }

    public function insertPurchase(SubscriptionPurchase $purchase):void
    {
        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_subscription_purchases '
            . '(purchase_id,user_id,plan_id,provider_key,idempotency_key,amount_minor,currency,duration_days,state,'
            . 'provider_reference,checkout_url,error_code,created_at_utc,updated_at_utc,activated_at_utc) '
            . 'VALUES (:id,:user,:plan,:provider,:idempotency,:amount,:currency,:duration,:state,:reference,:checkout,:error,:created,:updated,:activated)',
            self::purchaseParams($purchase)
        ));
    }

    public function savePurchase(SubscriptionPurchase $purchase):void
    {
        $this->database->execute(new CompiledQuery(
            'UPDATE forwext_subscription_purchases SET state=:state,provider_reference=:reference,checkout_url=:checkout,'
            . 'error_code=:error,updated_at_utc=:updated,activated_at_utc=:activated WHERE purchase_id=:id',
            self::purchaseParams($purchase)
        ));
    }

    public function purchasesForUser(EntityId $userId,int $limit=100):array
    {
        UserId::assert($userId);
        if($limit<1||$limit>200)throw new InvalidArgumentException('Subscription purchase list limit is invalid.');
        $rows=$this->database->fetchAll(new CompiledQuery(
            'SELECT * FROM forwext_subscription_purchases WHERE user_id=:user ORDER BY created_at_utc DESC,purchase_id DESC LIMIT '.$limit,
            ['user'=>$userId->value()]
        ));
        return array_map($this->hydratePurchase(...),$rows);
    }

    public function webhookEventExists(string $providerKey,string $eventId):bool
    {
        return (int)$this->database->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM forwext_subscription_webhook_events WHERE provider_key=:provider AND event_id=:event',
            ['provider'=>$providerKey,'event'=>$eventId]
        ))>0;
    }

    public function insertWebhookEvent(
        string $providerKey,string $eventId,EntityId $purchaseId,PaymentAttemptState $state,
        string $payloadHash,DateTimeImmutable $occurredAt,DateTimeImmutable $receivedAt
    ):void{
        if(preg_match('/^[a-f0-9]{64}$/D',$payloadHash)!==1)throw new InvalidArgumentException('Subscription webhook hash is invalid.');
        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_subscription_webhook_events '
            . '(provider_key,event_id,purchase_id,state,payload_sha256,occurred_at_utc,received_at_utc) '
            . 'VALUES (:provider,:event,:purchase,:state,:hash,:occurred,:received)',
            [
                'provider'=>$providerKey,'event'=>$eventId,'purchase'=>$purchaseId->value(),'state'=>$state->value,
                'hash'=>$payloadHash,'occurred'=>self::format($occurredAt),'received'=>self::format($receivedAt),
            ]
        ));
    }

    public function recordEvent(
        EntityId $subscriptionId,?EntityId $actor,string $action,SubscriptionState $fromState,SubscriptionState $toState,
        ?DateTimeImmutable $fromEndsAt,?DateTimeImmutable $toEndsAt,?EntityId $purchaseId,DateTimeImmutable $at
    ):void{
        if(preg_match('/^[a-z][a-z0-9._-]{1,63}$/D',$action)!==1){
            throw new InvalidArgumentException('Subscription event action is invalid.');
        }
        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_subscription_events '
            . '(event_id,subscription_id,actor_user_id,action,purchase_id,from_state,to_state,from_ends_at_utc,to_ends_at_utc,created_at_utc) '
            . 'VALUES (:id,:subscription,:actor,:action,:purchase,:from_state,:to_state,:from_ends,:to_ends,:created)',
            [
                'id'=>bin2hex(random_bytes(16)),'subscription'=>$subscriptionId->value(),'actor'=>$actor?->value(),
                'action'=>$action,'purchase'=>$purchaseId?->value(),'from_state'=>$fromState->value,'to_state'=>$toState->value,
                'from_ends'=>$fromEndsAt===null?null:self::format($fromEndsAt),
                'to_ends'=>$toEndsAt===null?null:self::format($toEndsAt),'created'=>self::format($at),
            ]
        ));
    }

    private function hydratePlan(array $row):SubscriptionPlan
    {
        return new SubscriptionPlan(
            EntityId::fromString((string)$row['plan_id']),(string)$row['plan_key'],(string)$row['name'],
            (string)$row['description'],(bool)$row['active'],(int)$row['price_minor'],(string)$row['currency'],
            $row['duration_days']===null?null:(int)$row['duration_days'],(int)$row['sort_order'],
            self::at((string)$row['created_at_utc']),self::at((string)$row['updated_at_utc'])
        );
    }

    private function hydrateSubscription(array $row):UserSubscription
    {
        return new UserSubscription(
            EntityId::fromString((string)$row['subscription_id']),EntityId::fromString((string)$row['user_id']),
            EntityId::fromString((string)$row['plan_id']),SubscriptionState::from((string)$row['state']),
            self::at((string)$row['starts_at_utc']),
            $row['ends_at_utc']===null?null:self::at((string)$row['ends_at_utc']),
            self::at((string)$row['created_at_utc']),self::at((string)$row['updated_at_utc'])
        );
    }

    private function hydratePurchase(array $row):SubscriptionPurchase
    {
        return new SubscriptionPurchase(
            EntityId::fromString((string)$row['purchase_id']),EntityId::fromString((string)$row['user_id']),
            EntityId::fromString((string)$row['plan_id']),(string)$row['provider_key'],(string)$row['idempotency_key'],
            (int)$row['amount_minor'],(string)$row['currency'],$row['duration_days']===null?null:(int)$row['duration_days'],
            PaymentAttemptState::from((string)$row['state']),
            self::nullable($row['provider_reference']??null),self::nullable($row['checkout_url']??null),
            self::nullable($row['error_code']??null),self::at((string)$row['created_at_utc']),
            self::at((string)$row['updated_at_utc']),
            $row['activated_at_utc']===null?null:self::at((string)$row['activated_at_utc'])
        );
    }

    private static function purchaseParams(SubscriptionPurchase $purchase):array
    {
        return [
            'id'=>$purchase->purchaseId->value(),'user'=>$purchase->userId->value(),'plan'=>$purchase->planId->value(),
            'provider'=>$purchase->providerKey,'idempotency'=>$purchase->idempotencyKey,'amount'=>$purchase->amountMinor,
            'currency'=>$purchase->currency,'duration'=>$purchase->durationDays,'state'=>$purchase->state->value,
            'reference'=>$purchase->providerReference,'checkout'=>$purchase->checkoutUrl,'error'=>$purchase->errorCode,
            'created'=>self::format($purchase->createdAt),'updated'=>self::format($purchase->updatedAt),
            'activated'=>$purchase->activatedAt===null?null:self::format($purchase->activatedAt),
        ];
    }

    private static function nullable(mixed $value):?string{return $value===null?null:(string)$value;}
    private static function at(string $value):DateTimeImmutable{return new DateTimeImmutable($value,new DateTimeZone('UTC'));}
    private static function format(DateTimeImmutable $value):string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
