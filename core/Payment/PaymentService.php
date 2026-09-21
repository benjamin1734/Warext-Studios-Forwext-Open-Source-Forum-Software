<?php

declare(strict_types=1);

namespace Forwext\Core\Payment;

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
use Forwext\Core\Marketplace\MarketplaceDeliveryState;
use Forwext\Core\Marketplace\MarketplaceOrder;
use Forwext\Core\Marketplace\MarketplaceOrderState;
use Forwext\Core\Marketplace\MarketplacePaymentState;
use Forwext\Core\Marketplace\MarketplacePurchaseRepository;
use Forwext\Core\Marketplace\MarketplaceService;
use InvalidArgumentException;
use Throwable;

final readonly class PaymentService
{
    public function __construct(
        private TransactionalQueryExecutor $database,
        private PaymentRepository $payments,
        private PaymentProviderRegistry $providers,
        private MarketplacePurchaseRepository $orders,
        private MarketplaceService $marketplace,
        private PermissionAuthorizer $authorizer,
        private AuditRecorder $audit,
    ){}

    /** @return list<string> */
    public function providerKeys():array{return $this->providers->keys();}

    public function initiate(
        EntityId $buyer,
        EntityId $orderId,
        string $providerKey,
        string $idempotencyKey,
        string $returnPath,
        string $cancelPath,
        DateTimeImmutable $now,
    ):PaymentAttempt{
        $this->marketplace->requirePurchase($buyer);
        self::assertIdempotencyKey($idempotencyKey);
        $provider=$this->providers->require($providerKey);
        $at=self::utc($now);

        $attempt=$this->database->transaction(function()use(
            $buyer,$orderId,$providerKey,$idempotencyKey,$at
        ):PaymentAttempt{
            $order=$this->orders->order($orderId,true)
                ??throw new InvalidArgumentException('Marketplace order was not found.');
            if(!$order->buyerUserId->equals($buyer))throw new InvalidArgumentException('Marketplace order was not found.');

            $existing=$this->payments->attemptByIdempotency($orderId,$providerKey,$idempotencyKey);
            if($existing!==null)return $existing;

            if($order->state!==MarketplaceOrderState::Pending
                ||!in_array(
                    $order->paymentState,
                    [MarketplacePaymentState::Pending,MarketplacePaymentState::Failed,MarketplacePaymentState::Cancelled],
                    true
                )
            ){
                throw new InvalidArgumentException('Marketplace order is not eligible for a new payment attempt.');
            }

            $activeId=$order->receiptMetadata['payment_attempt_id']??null;
            if(is_string($activeId)&&preg_match('/^[a-f0-9]{32}$/D',$activeId)===1){
                $active=$this->payments->attempt(EntityId::fromString($activeId),true);
                if($active!==null&&in_array(
                    $active->state,
                    [PaymentAttemptState::Pending,PaymentAttemptState::RequiresAction,PaymentAttemptState::Authorized],
                    true
                )){
                    throw new InvalidArgumentException('Marketplace order already has an active payment attempt.');
                }
            }

            $attempt=new PaymentAttempt(
                PaymentAttempt::generateId(),$order->orderId,$buyer,$providerKey,$idempotencyKey,
                $order->totalMinor,$order->currency,PaymentAttemptState::Pending,
                null,null,null,$at,$at
            );
            $this->payments->insertAttempt($attempt);
            $this->setActiveAttemptOnOrder($order,$attempt,$buyer,$at,'payment.attempt.start');
            return $attempt;
        });

        if($attempt->providerReference!==null||$attempt->state!==PaymentAttemptState::Pending)return $attempt;

        try{
            $result=$provider->create(new PaymentCreateRequest(
                $attempt->attemptId,$attempt->orderId,$attempt->buyerUserId,$attempt->amountMinor,$attempt->currency,
                $attempt->idempotencyKey,$returnPath,$cancelPath
            ));
        }catch(Throwable $exception){
            throw new PaymentProviderException('Payment provider initiation failed.',previous:$exception);
        }
        if($result->state===PaymentAttemptState::Refunded){
            throw new PaymentProviderException('Payment provider returned an invalid initiation state.');
        }

        return $this->database->transaction(function()use($attempt,$result,$buyer,$at):PaymentAttempt{
            $current=$this->payments->attempt($attempt->attemptId,true)
                ??throw new InvalidArgumentException('Payment attempt was not found.');
            if($current->providerReference!==null&&!hash_equals($current->providerReference,$result->providerReference)){
                throw new InvalidArgumentException('Payment provider reference changed for an idempotent attempt.');
            }

            if($current->state!==PaymentAttemptState::Pending){
                if($current->providerReference===null){
                    $current=$this->withAttemptState(
                        $current,$current->state,$result->providerReference,
                        $current->state===PaymentAttemptState::RequiresAction?$result->checkoutUrl:$current->checkoutUrl,
                        $current->errorCode,$at
                    );
                    $this->payments->saveAttempt($current);
                }
                return $current;
            }

            $updated=$this->withAttemptState(
                $current,$result->state,$result->providerReference,$result->checkoutUrl,$result->errorCode,$at
            );
            $this->payments->saveAttempt($updated);
            $this->syncOrderFromAttempt($updated,$buyer,$at,'payment.provider.'.$updated->state->value);
            return $updated;
        });
    }

    public function handleWebhook(
        string $providerKey,
        PaymentWebhookRequest $request,
    ):PaymentAttempt{
        $provider=$this->providers->require($providerKey);
        try{
            $event=$provider->verifyWebhook($request);
        }catch(PaymentWebhookVerificationException $exception){
            throw $exception;
        }catch(Throwable $exception){
            throw new PaymentWebhookVerificationException('Payment webhook verification failed.',previous:$exception);
        }

        $attempt=$event->attemptId!==null
            ?$this->payments->attempt($event->attemptId)
            :($event->providerReference===null?null:$this->payments->attemptByProviderReference($providerKey,$event->providerReference));
        if($attempt===null||!hash_equals($attempt->providerKey,$providerKey)){
            throw new InvalidArgumentException('Payment webhook does not reference a known provider attempt.');
        }
        if($event->providerReference!==null&&$attempt->providerReference!==null
            &&!hash_equals($attempt->providerReference,$event->providerReference)
        ){
            throw new InvalidArgumentException('Payment webhook provider reference does not match the attempt.');
        }
        if($event->amountMinor!==null&&$event->amountMinor!==$attempt->amountMinor){
            throw new InvalidArgumentException('Payment webhook amount does not match the attempt.');
        }
        if($event->currency!==null&&!hash_equals($attempt->currency,$event->currency)){
            throw new InvalidArgumentException('Payment webhook currency does not match the attempt.');
        }
        if($event->state===PaymentAttemptState::Refunded
            &&$event->amountMinor!==null&&$event->amountMinor!==$attempt->amountMinor
        ){
            throw new InvalidArgumentException('Partial webhook refunds are not supported by the core refund model.');
        }

        return $this->database->transaction(function()use($providerKey,$request,$event,$attempt):PaymentAttempt{
            if($this->payments->webhookEventExists($providerKey,$event->eventId)){
                return $this->payments->attempt($attempt->attemptId)??$attempt;
            }

            $current=$this->payments->attempt($attempt->attemptId)
                ??throw new InvalidArgumentException('Payment attempt was not found.');
            $this->payments->insertWebhookEvent(
                $providerKey,$event->eventId,$current->attemptId,$event->state,
                hash('sha256',$request->rawBody),$event->occurredAt,$request->receivedAt
            );

            if(!self::shouldApplyState($current->state,$event->state))return $current;
            $reference=$current->providerReference??$event->providerReference;
            $updated=$this->withAttemptState(
                $current,$event->state,$reference,$current->checkoutUrl,null,$request->receivedAt
            );
            $this->payments->saveAttempt($updated);
            $this->syncOrderFromAttempt(
                $updated,null,$request->receivedAt,'payment.webhook.'.$event->state->value
            );
            return $updated;
        });
    }

    public function refund(
        EntityId $actor,
        EntityId $attemptId,
        string $idempotencyKey,
        DateTimeImmutable $now,
        ?AuditRequestId $requestId=null,
    ):PaymentRefund{
        $this->require($actor,'payment.refund');
        self::assertIdempotencyKey($idempotencyKey);
        $at=self::utc($now);
        $attempt=$this->payments->attempt($attemptId)??throw new InvalidArgumentException('Payment attempt was not found.');
        if($attempt->state!==PaymentAttemptState::Paid||$attempt->providerReference===null){
            throw new InvalidArgumentException('Only a paid provider attempt can be refunded.');
        }
        $provider=$this->providers->require($attempt->providerKey);
        if(!$provider->capabilities()->refunds)throw new InvalidArgumentException('Payment provider does not support refunds.');

        $refund=$this->database->transaction(function()use($actor,$attempt,$idempotencyKey,$at):PaymentRefund{
            $existing=$this->payments->refundByIdempotency($attempt->attemptId,$idempotencyKey);
            if($existing!==null)return $existing;
            $refund=new PaymentRefund(
                PaymentRefund::generateId(),$attempt->attemptId,$actor,$idempotencyKey,$attempt->amountMinor,
                PaymentRefundState::Pending,null,null,$at,$at
            );
            $this->payments->insertRefund($refund);
            return $refund;
        });
        if($refund->state!==PaymentRefundState::Pending&&$refund->providerRefundReference!==null)return $refund;

        try{
            $result=$provider->refund(new PaymentRefundRequest(
                $refund->refundId,$attempt->attemptId,$attempt->providerReference,$attempt->amountMinor,
                $attempt->currency,$refund->idempotencyKey
            ));
        }catch(Throwable $exception){
            throw new PaymentProviderException('Payment provider refund failed.',previous:$exception);
        }

        $before=['state'=>$refund->state->value];
        $after=['state'=>$result->state->value];
        $event=new AuditEvent(
            AuditEvent::generateId(),AuditScope::Administration,$actor,AuditAction::fromString('payment.refund'),
            'payment.refund',$refund->refundId->value(),null,'payment.refund',$requestId??AuditRequestId::generate(),
            $before,$after,$at
        );

        return $this->audit->mutate($event,function()use($refund,$result,$attempt,$actor,$at):PaymentRefund{
            $updated=new PaymentRefund(
                $refund->refundId,$refund->attemptId,$refund->actorUserId,$refund->idempotencyKey,$refund->amountMinor,
                $result->state,$result->providerRefundReference,$result->errorCode,$refund->createdAt,$at
            );
            $this->payments->saveRefund($updated);
            if($updated->state===PaymentRefundState::Succeeded){
                $current=$this->payments->attempt($attempt->attemptId)??$attempt;
                if($current->state===PaymentAttemptState::Paid){
                    $refunded=$this->withAttemptState(
                        $current,PaymentAttemptState::Refunded,$current->providerReference,null,null,$at
                    );
                    $this->payments->saveAttempt($refunded);
                    $this->syncOrderFromAttempt($refunded,$actor,$at,'payment.refund.succeeded');
                }
            }
            return $updated;
        });
    }

    public function cancel(
        EntityId $actor,
        EntityId $attemptId,
        string $idempotencyKey,
        DateTimeImmutable $now,
        ?AuditRequestId $requestId=null,
    ):PaymentAttempt{
        $this->require($actor,'payment.refund');
        self::assertIdempotencyKey($idempotencyKey);
        $at=self::utc($now);
        $attempt=$this->payments->attempt($attemptId)??throw new InvalidArgumentException('Payment attempt was not found.');
        if($attempt->state===PaymentAttemptState::Cancelled)return $attempt;
        if(!in_array($attempt->state,[PaymentAttemptState::Pending,PaymentAttemptState::RequiresAction,PaymentAttemptState::Authorized],true)){
            throw new InvalidArgumentException('Payment attempt cannot be cancelled in its current state.');
        }

        $result=null;
        if($attempt->providerReference!==null){
            $provider=$this->providers->require($attempt->providerKey);
            if(!$provider->capabilities()->cancellation)throw new InvalidArgumentException('Payment provider does not support cancellation.');
            try{
                $result=$provider->cancel(new PaymentCancelRequest(
                    $attempt->attemptId,$attempt->providerReference,$idempotencyKey
                ));
            }catch(Throwable $exception){
                throw new PaymentProviderException('Payment provider cancellation failed.',previous:$exception);
            }
            if($result->state!==PaymentAttemptState::Cancelled){
                throw new PaymentProviderException('Payment provider did not confirm cancellation.');
            }
        }

        $event=new AuditEvent(
            AuditEvent::generateId(),AuditScope::Administration,$actor,AuditAction::fromString('payment.cancel'),
            'payment.attempt',$attempt->attemptId->value(),null,'payment.cancel',$requestId??AuditRequestId::generate(),
            ['state'=>$attempt->state->value],['state'=>PaymentAttemptState::Cancelled->value],$at
        );
        return $this->audit->mutate($event,function()use($attempt,$result,$actor,$at):PaymentAttempt{
            $updated=$this->withAttemptState(
                $attempt,PaymentAttemptState::Cancelled,
                $result?->providerReference??$attempt->providerReference,null,$result?->errorCode,$at
            );
            $this->payments->saveAttempt($updated);
            $this->syncOrderFromAttempt($updated,$actor,$at,'payment.cancelled');
            return $updated;
        });
    }

    /** @return list<PaymentAttempt> */
    public function attempts(EntityId $actor,int $limit=100):array
    {
        $this->require($actor,'payment.manage');
        return $this->payments->attempts($limit);
    }

    /** @return list<PaymentRefund> */
    public function refunds(EntityId $actor,EntityId $attemptId):array
    {
        if(!$this->allows($actor,'payment.manage')&&!$this->allows($actor,'payment.refund')){
            $this->require($actor,'payment.manage');
        }
        return $this->payments->refundsForAttempt($attemptId);
    }

    private function setActiveAttemptOnOrder(
        MarketplaceOrder $order,PaymentAttempt $attempt,?EntityId $actor,DateTimeImmutable $at,string $action
    ):void{
        if($order->totalMinor!==$attempt->amountMinor||!hash_equals($order->currency,$attempt->currency)
            ||!$order->buyerUserId->equals($attempt->buyerUserId)
        ){
            throw new InvalidArgumentException('Payment attempt does not match its order snapshot.');
        }
        $metadata=$order->receiptMetadata;
        $metadata['payment_provider']=$attempt->providerKey;
        $metadata['payment_attempt_id']=$attempt->attemptId->value();
        $updated=new MarketplaceOrder(
            $order->orderId,$order->orderNumber,$order->checkoutKey,$order->buyerUserId,$order->sellerUserId,
            $order->currency,$order->subtotalMinor,$order->totalMinor,$order->state,MarketplacePaymentState::Pending,
            $order->deliveryState,$order->billing,$metadata,$order->createdAt,$at
        );
        $this->orders->saveOrderStates($updated);
        $this->orders->recordOrderHistory(
            $order->orderId,$actor,$action,$order->state,$updated->state,$order->paymentState,$updated->paymentState,
            $order->deliveryState,$updated->deliveryState,$at
        );
    }

    private function syncOrderFromAttempt(
        PaymentAttempt $attempt,?EntityId $actor,DateTimeImmutable $at,string $action
    ):void{
        $order=$this->orders->order($attempt->orderId)??throw new InvalidArgumentException('Marketplace order was not found.');
        if($order->totalMinor!==$attempt->amountMinor||!hash_equals($order->currency,$attempt->currency)
            ||!$order->buyerUserId->equals($attempt->buyerUserId)
        ){
            throw new InvalidArgumentException('Payment attempt does not match its order snapshot.');
        }

        $active=$order->receiptMetadata['payment_attempt_id']??null;
        $isActive=is_string($active)&&hash_equals($active,$attempt->attemptId->value());
        $payment=$order->paymentState;
        $orderState=$order->state;
        $delivery=$order->deliveryState;

        if($attempt->state===PaymentAttemptState::Paid){
            $payment=MarketplacePaymentState::Paid;
            $orderState=MarketplaceOrderState::Confirmed;
        }elseif($attempt->state===PaymentAttemptState::Refunded&&$isActive){
            $payment=MarketplacePaymentState::Refunded;
        }elseif($isActive&&!in_array($order->paymentState,[MarketplacePaymentState::Paid,MarketplacePaymentState::Refunded],true)){
            $payment=match($attempt->state){
                PaymentAttemptState::Authorized=>MarketplacePaymentState::Authorized,
                PaymentAttemptState::Failed=>MarketplacePaymentState::Failed,
                PaymentAttemptState::Cancelled=>MarketplacePaymentState::Cancelled,
                default=>MarketplacePaymentState::Pending,
            };
        }else{
            return;
        }

        $metadata=$order->receiptMetadata;
        if($attempt->state===PaymentAttemptState::Paid){
            $metadata['payment_provider']=$attempt->providerKey;
            $metadata['payment_attempt_id']=$attempt->attemptId->value();
        }
        $updated=new MarketplaceOrder(
            $order->orderId,$order->orderNumber,$order->checkoutKey,$order->buyerUserId,$order->sellerUserId,
            $order->currency,$order->subtotalMinor,$order->totalMinor,$orderState,$payment,$delivery,
            $order->billing,$metadata,$order->createdAt,$at
        );
        if($updated->state===$order->state&&$updated->paymentState===$order->paymentState
            &&$updated->deliveryState===$order->deliveryState&&$updated->receiptMetadata===$order->receiptMetadata
        )return;
        $this->orders->saveOrderStates($updated);
        $this->orders->recordOrderHistory(
            $order->orderId,$actor,$action,$order->state,$updated->state,$order->paymentState,$updated->paymentState,
            $order->deliveryState,$updated->deliveryState,$at
        );
    }

    private function withAttemptState(
        PaymentAttempt $attempt,PaymentAttemptState $state,?string $reference,?string $checkoutUrl,
        ?string $errorCode,DateTimeImmutable $at
    ):PaymentAttempt{
        return new PaymentAttempt(
            $attempt->attemptId,$attempt->orderId,$attempt->buyerUserId,$attempt->providerKey,$attempt->idempotencyKey,
            $attempt->amountMinor,$attempt->currency,$state,$reference,$checkoutUrl,$errorCode,$attempt->createdAt,$at
        );
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
        if(preg_match('/^[a-f0-9]{32}$/D',$key)!==1)throw new InvalidArgumentException('Payment idempotency key is invalid.');
    }

    private static function utc(DateTimeImmutable $at):DateTimeImmutable
    {
        return $at->setTimezone(new DateTimeZone('UTC'));
    }
}
