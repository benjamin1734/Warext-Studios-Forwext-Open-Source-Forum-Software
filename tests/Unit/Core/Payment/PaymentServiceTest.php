<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Payment;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Audit\AuditEvent;
use Forwext\Core\Audit\AuditRecorder;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDefinition;
use Forwext\Core\Domain\Access\Permission\PermissionEffect;
use Forwext\Core\Domain\Access\Permission\PermissionEngine;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Access\Permission\PermissionRule;
use Forwext\Core\Domain\Access\Permission\PermissionRuleRepository;
use Forwext\Core\Domain\Access\Permission\PermissionSubjectType;
use Forwext\Core\Domain\Access\Permission\PermissionValueType;
use Forwext\Core\Domain\Access\UserAccessAssignment;
use Forwext\Core\Domain\Access\UserAccessAssignmentProvider;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Marketplace\MarketplaceBillingSnapshot;
use Forwext\Core\Marketplace\MarketplaceDeliveryState;
use Forwext\Core\Marketplace\MarketplaceOrder;
use Forwext\Core\Marketplace\MarketplaceOrderItem;
use Forwext\Core\Marketplace\MarketplaceOrderState;
use Forwext\Core\Marketplace\MarketplacePaymentState;
use Forwext\Core\Marketplace\MarketplacePurchaseRepository;
use Forwext\Core\Payment\PaymentAttempt;
use Forwext\Core\Payment\PaymentAttemptState;
use Forwext\Core\Payment\PaymentCancelRequest;
use Forwext\Core\Payment\PaymentCreateRequest;
use Forwext\Core\Payment\PaymentProvider;
use Forwext\Core\Payment\PaymentProviderCapabilities;
use Forwext\Core\Payment\PaymentProviderRegistry;
use Forwext\Core\Payment\PaymentProviderResult;
use Forwext\Core\Payment\PaymentRefund;
use Forwext\Core\Payment\PaymentRefundRequest;
use Forwext\Core\Payment\PaymentRefundResult;
use Forwext\Core\Payment\PaymentRefundState;
use Forwext\Core\Payment\PaymentRepository;
use Forwext\Core\Payment\PaymentService;
use Forwext\Core\Payment\PaymentWebhookEvent;
use Forwext\Core\Payment\PaymentWebhookRequest;
use Forwext\Core\Payment\PaymentWebhookVerificationException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PaymentServiceTest extends TestCase
{
    public function testRegistryRejectsDuplicateProviderKeys():void
    {
        $provider=new PaymentFakeProvider('fake');
        $registry=new PaymentProviderRegistry([$provider]);
        self::assertSame(['fake'],$registry->keys());

        $this->expectException(InvalidArgumentException::class);
        $registry->register(new PaymentFakeProvider('fake'));
    }

    public function testInitiationWebhookIdempotencyAndStateMonotonicity():void
    {
        $buyer=UserId::generate();
        $seller=UserId::generate();
        $admin=UserId::generate();
        $order=$this->order($buyer,$seller);
        $orders=new PaymentMemoryOrderRepository($order);
        $payments=new PaymentMemoryRepository();
        $provider=new PaymentFakeProvider('fake');
        $provider->createResult=new PaymentProviderResult(
            PaymentAttemptState::RequiresAction,'pay_001','https://pay.example.test/session/1'
        );
        $service=$this->service($payments,$orders,$provider,[
            $buyer->value()=>['marketplace.purchase'=>true],
            $admin->value()=>['payment.manage'=>true,'payment.refund'=>true],
        ]);

        $attempt=$service->initiate(
            $buyer,$order->orderId,'fake',str_repeat('a',32),
            '/marketplace/orders/'.$order->orderId->value(),
            '/marketplace/orders/'.$order->orderId->value(),
            $this->at('2026-09-21 12:00:00')
        );
        self::assertSame(PaymentAttemptState::RequiresAction,$attempt->state);
        self::assertSame(1,$provider->createCalls);
        self::assertSame($attempt->attemptId->value(),$orders->order($order->orderId)?->receiptMetadata['payment_attempt_id']);

        $same=$service->initiate(
            $buyer,$order->orderId,'fake',str_repeat('a',32),
            '/marketplace/orders/'.$order->orderId->value(),
            '/marketplace/orders/'.$order->orderId->value(),
            $this->at('2026-09-21 12:01:00')
        );
        self::assertSame($attempt->attemptId->value(),$same->attemptId->value());
        self::assertSame(1,$provider->createCalls);

        try{
            $service->initiate(
                $buyer,$order->orderId,'fake',str_repeat('b',32),
                '/marketplace/orders/'.$order->orderId->value(),
                '/marketplace/orders/'.$order->orderId->value(),
                $this->at('2026-09-21 12:02:00')
            );
            self::fail('A second active attempt must be blocked.');
        }catch(InvalidArgumentException){}

        $provider->webhookEvent=new PaymentWebhookEvent(
            'evt_paid_1',PaymentAttemptState::Paid,$attempt->attemptId,'pay_001',null,12500,'TRY',
            $this->at('2026-09-21 12:03:00')
        );
        $paid=$service->handleWebhook('fake',$this->webhook('paid-body',$this->at('2026-09-21 12:03:01')));
        self::assertSame(PaymentAttemptState::Paid,$paid->state);
        self::assertSame(MarketplaceOrderState::Confirmed,$orders->order($order->orderId)?->state);
        self::assertSame(MarketplacePaymentState::Paid,$orders->order($order->orderId)?->paymentState);
        self::assertCount(1,$payments->events);

        $duplicate=$service->handleWebhook('fake',$this->webhook('paid-body',$this->at('2026-09-21 12:03:02')));
        self::assertSame(PaymentAttemptState::Paid,$duplicate->state);
        self::assertCount(1,$payments->events);

        $provider->webhookEvent=new PaymentWebhookEvent(
            'evt_stale_failed',PaymentAttemptState::Failed,$attempt->attemptId,'pay_001',null,12500,'TRY',
            $this->at('2026-09-21 12:02:30')
        );
        $stale=$service->handleWebhook('fake',$this->webhook('failed-body',$this->at('2026-09-21 12:04:00')));
        self::assertSame(PaymentAttemptState::Paid,$stale->state);
        self::assertSame(MarketplacePaymentState::Paid,$orders->order($order->orderId)?->paymentState);

        $provider->webhookEvent=new PaymentWebhookEvent(
            'evt_bad_amount',PaymentAttemptState::Paid,$attempt->attemptId,'pay_001',null,12499,'TRY',
            $this->at('2026-09-21 12:05:00')
        );
        try{
            $service->handleWebhook('fake',$this->webhook('bad-body',$this->at('2026-09-21 12:05:01')));
            self::fail('Mismatched webhook amounts must be rejected.');
        }catch(InvalidArgumentException){}
        self::assertCount(2,$payments->events);
    }

    public function testRefundAndCancellationAreIdempotent():void
    {
        $buyer=UserId::generate();
        $seller=UserId::generate();
        $admin=UserId::generate();
        $order=$this->order($buyer,$seller);
        $orders=new PaymentMemoryOrderRepository($order);
        $payments=new PaymentMemoryRepository();
        $provider=new PaymentFakeProvider('fake');
        $provider->createResult=new PaymentProviderResult(PaymentAttemptState::Paid,'pay_002');
        $service=$this->service($payments,$orders,$provider,[
            $buyer->value()=>['marketplace.purchase'=>true],
            $admin->value()=>['payment.manage'=>true,'payment.refund'=>true],
        ]);

        $paid=$service->initiate(
            $buyer,$order->orderId,'fake',str_repeat('c',32),
            '/return','/cancel',$this->at('2026-09-21 13:00:00')
        );
        self::assertSame(PaymentAttemptState::Paid,$paid->state);

        $provider->refundResult=new PaymentRefundResult(PaymentRefundState::Succeeded,'refund_001');
        $refund=$service->refund($admin,$paid->attemptId,str_repeat('d',32),$this->at('2026-09-21 13:01:00'));
        self::assertSame(PaymentRefundState::Succeeded,$refund->state);
        self::assertSame(1,$provider->refundCalls);
        self::assertSame(PaymentAttemptState::Refunded,$payments->attempt($paid->attemptId)?->state);
        self::assertSame(MarketplacePaymentState::Refunded,$orders->order($order->orderId)?->paymentState);

        $same=$service->refund($admin,$paid->attemptId,str_repeat('d',32),$this->at('2026-09-21 13:02:00'));
        self::assertSame($refund->refundId->value(),$same->refundId->value());
        self::assertSame(1,$provider->refundCalls);

        $order2=$this->order($buyer,$seller,$this->at('2026-09-21 14:00:00'));
        $orders->store($order2);
        $provider->createResult=new PaymentProviderResult(
            PaymentAttemptState::RequiresAction,'pay_003','https://pay.example.test/session/3'
        );
        $pending=$service->initiate(
            $buyer,$order2->orderId,'fake',str_repeat('e',32),
            '/return','/cancel',$this->at('2026-09-21 14:01:00')
        );
        $provider->cancelResult=new PaymentProviderResult(PaymentAttemptState::Cancelled,'pay_003');
        $cancelled=$service->cancel($admin,$pending->attemptId,str_repeat('f',32),$this->at('2026-09-21 14:02:00'));
        self::assertSame(PaymentAttemptState::Cancelled,$cancelled->state);
        self::assertSame(MarketplacePaymentState::Cancelled,$orders->order($order2->orderId)?->paymentState);
        self::assertSame(1,$provider->cancelCalls);

        $again=$service->cancel($admin,$pending->attemptId,str_repeat('f',32),$this->at('2026-09-21 14:03:00'));
        self::assertSame(PaymentAttemptState::Cancelled,$again->state);
        self::assertSame(1,$provider->cancelCalls);
    }

    public function testBuyerOrderCancellationClosesActiveProviderAttempt():void
    {
        $buyer=UserId::generate();
        $seller=UserId::generate();
        $order=$this->order($buyer,$seller,$this->at('2026-09-21 14:10:00'));
        $orders=new PaymentMemoryOrderRepository($order);
        $payments=new PaymentMemoryRepository();
        $provider=new PaymentFakeProvider('fake');
        $provider->createResult=new PaymentProviderResult(
            PaymentAttemptState::RequiresAction,'pay_buyer_cancel','https://pay.example.test/session/cancel'
        );
        $service=$this->service($payments,$orders,$provider,[
            $buyer->value()=>['marketplace.purchase'=>true],
        ]);

        $attempt=$service->initiate(
            $buyer,$order->orderId,'fake',str_repeat('8',32),
            '/return','/cancel',$this->at('2026-09-21 14:11:00')
        );
        self::assertTrue($service->hasActiveAttempt($orders->order($order->orderId)));
        $provider->cancelResult=new PaymentProviderResult(PaymentAttemptState::Cancelled,'pay_buyer_cancel');

        $cancelled=$service->cancelForOrderBuyer(
            $buyer,$order->orderId,str_repeat('9',32),$this->at('2026-09-21 14:12:00')
        );
        self::assertNotNull($cancelled);
        self::assertSame(PaymentAttemptState::Cancelled,$cancelled->state);
        self::assertSame(1,$provider->cancelCalls);

        $updatedOrder=$orders->order($order->orderId);
        self::assertNotNull($updatedOrder);
        self::assertSame(MarketplacePaymentState::Cancelled,$updatedOrder->paymentState);
        self::assertArrayNotHasKey('payment_attempt_id',$updatedOrder->receiptMetadata);
        self::assertFalse($service->hasActiveAttempt($updatedOrder));

        $again=$service->cancelForOrderBuyer(
            $buyer,$order->orderId,str_repeat('9',32),$this->at('2026-09-21 14:13:00')
        );
        self::assertNull($again);
        self::assertSame(1,$provider->cancelCalls);
    }

    public function testPendingRefundCompletesFromVerifiedWebhook():void
    {
        $buyer=UserId::generate();
        $seller=UserId::generate();
        $admin=UserId::generate();
        $order=$this->order($buyer,$seller,$this->at('2026-09-21 14:30:00'));
        $orders=new PaymentMemoryOrderRepository($order);
        $payments=new PaymentMemoryRepository();
        $provider=new PaymentFakeProvider('fake');
        $provider->createResult=new PaymentProviderResult(PaymentAttemptState::Paid,'pay_async');
        $service=$this->service($payments,$orders,$provider,[
            $buyer->value()=>['marketplace.purchase'=>true],
            $admin->value()=>['payment.manage'=>true,'payment.refund'=>true],
        ]);

        $paid=$service->initiate(
            $buyer,$order->orderId,'fake',str_repeat('1',32),
            '/return','/cancel',$this->at('2026-09-21 14:31:00')
        );
        $provider->refundResult=new PaymentRefundResult(PaymentRefundState::Pending,'refund_async');
        $pending=$service->refund(
            $admin,$paid->attemptId,str_repeat('2',32),$this->at('2026-09-21 14:32:00')
        );
        self::assertSame(PaymentRefundState::Pending,$pending->state);
        self::assertSame(1,$provider->refundCalls);

        $same=$service->refund(
            $admin,$paid->attemptId,str_repeat('2',32),$this->at('2026-09-21 14:33:00')
        );
        self::assertSame($pending->refundId->value(),$same->refundId->value());
        self::assertSame(1,$provider->refundCalls);

        $provider->webhookEvent=new PaymentWebhookEvent(
            'evt_refund_async',PaymentAttemptState::Refunded,$paid->attemptId,'pay_async','refund_async',
            12500,'TRY',$this->at('2026-09-21 14:34:00')
        );
        $refunded=$service->handleWebhook(
            'fake',$this->webhook('refund-body',$this->at('2026-09-21 14:34:01'))
        );
        self::assertSame(PaymentAttemptState::Refunded,$refunded->state);
        self::assertSame(MarketplacePaymentState::Refunded,$orders->order($order->orderId)?->paymentState);
        $refunds=$payments->refundsForAttempt($paid->attemptId);
        self::assertCount(1,$refunds);
        self::assertSame(PaymentRefundState::Succeeded,$refunds[0]->state);
    }

    public function testWebhookVerificationFailureIsFailClosed():void
    {
        $buyer=UserId::generate();$seller=UserId::generate();
        $orders=new PaymentMemoryOrderRepository($this->order($buyer,$seller));
        $payments=new PaymentMemoryRepository();
        $provider=new PaymentFakeProvider('fake');
        $service=$this->service($payments,$orders,$provider,[$buyer->value()=>['marketplace.purchase'=>true]]);

        $this->expectException(PaymentWebhookVerificationException::class);
        $service->handleWebhook('fake',new PaymentWebhookRequest(
            '{}',['x-signature'=>['invalid']],$this->at('2026-09-21 15:00:00')
        ));
    }

    private function service(
        PaymentMemoryRepository $payments,
        PaymentMemoryOrderRepository $orders,
        PaymentFakeProvider $provider,
        array $permissions,
    ):PaymentService{
        $authorizer=new PermissionAuthorizer(
            new PermissionEngine(new PaymentPermissionRules($permissions)),
            new PaymentAssignments(array_keys($permissions))
        );
        return new PaymentService(
            new PaymentTestDatabase(),$payments,new PaymentProviderRegistry([$provider]),$orders,
            $authorizer,new PaymentAudit()
        );
    }

    private function order(EntityId $buyer,EntityId $seller,?DateTimeImmutable $at=null):MarketplaceOrder
    {
        $at??=$this->at('2026-09-21 11:00:00');
        return new MarketplaceOrder(
            MarketplaceOrder::generateId(),MarketplaceOrder::generateNumber($at),bin2hex(random_bytes(16)),
            $buyer,$seller,'TRY',12500,12500,MarketplaceOrderState::Pending,MarketplacePaymentState::Pending,
            MarketplaceDeliveryState::Pending,new MarketplaceBillingSnapshot('Buyer Test','buyer@example.com','TR'),
            ['checkout_mode'=>'internal','item_count'=>1],$at,$at
        );
    }

    private function webhook(string $body,DateTimeImmutable $at):PaymentWebhookRequest
    {
        return new PaymentWebhookRequest($body,['x-signature'=>['ok']],$at);
    }

    private function at(string $value):DateTimeImmutable
    {
        return new DateTimeImmutable($value,new DateTimeZone('UTC'));
    }
}

final class PaymentFakeProvider implements PaymentProvider
{
    public int $createCalls=0;
    public int $refundCalls=0;
    public int $cancelCalls=0;
    public PaymentProviderResult $createResult;
    public PaymentRefundResult $refundResult;
    public PaymentProviderResult $cancelResult;
    public ?PaymentWebhookEvent $webhookEvent=null;

    public function __construct(private string $providerKey)
    {
        $this->createResult=new PaymentProviderResult(PaymentAttemptState::Failed,'default_create',null,'provider_error');
        $this->refundResult=new PaymentRefundResult(PaymentRefundState::Failed,'default_refund','provider_error');
        $this->cancelResult=new PaymentProviderResult(PaymentAttemptState::Cancelled,'default_cancel');
    }

    public function key():string{return $this->providerKey;}
    public function capabilities():PaymentProviderCapabilities{return new PaymentProviderCapabilities(true,true);}

    public function create(PaymentCreateRequest $request):PaymentProviderResult
    {
        ++$this->createCalls;return $this->createResult;
    }

    public function verifyWebhook(PaymentWebhookRequest $request):PaymentWebhookEvent
    {
        if($request->firstHeader('x-signature')!=='ok'){
            throw new PaymentWebhookVerificationException('Invalid fake signature.');
        }
        return $this->webhookEvent??throw new PaymentWebhookVerificationException('Missing fake event.');
    }

    public function cancel(PaymentCancelRequest $request):PaymentProviderResult
    {
        ++$this->cancelCalls;return $this->cancelResult;
    }

    public function refund(PaymentRefundRequest $request):PaymentRefundResult
    {
        ++$this->refundCalls;return $this->refundResult;
    }
}

final class PaymentMemoryRepository implements PaymentRepository
{
    /** @var array<string,PaymentAttempt> */
    private array $attempts=[];
    /** @var array<string,PaymentRefund> */
    private array $refunds=[];
    /** @var array<string,true> */
    public array $events=[];

    public function attempt(EntityId $attemptId,bool $forUpdate=false):?PaymentAttempt
    {
        return $this->attempts[$attemptId->value()]??null;
    }

    public function attemptByIdempotency(EntityId $orderId,string $providerKey,string $idempotencyKey):?PaymentAttempt
    {
        foreach($this->attempts as $attempt){
            if($attempt->orderId->equals($orderId)&&$attempt->providerKey===$providerKey&&$attempt->idempotencyKey===$idempotencyKey)return $attempt;
        }
        return null;
    }

    public function attemptByProviderReference(string $providerKey,string $providerReference):?PaymentAttempt
    {
        foreach($this->attempts as $attempt){
            if($attempt->providerKey===$providerKey&&$attempt->providerReference===$providerReference)return $attempt;
        }
        return null;
    }

    public function insertAttempt(PaymentAttempt $attempt):void{$this->attempts[$attempt->attemptId->value()]=$attempt;}
    public function saveAttempt(PaymentAttempt $attempt):void{$this->attempts[$attempt->attemptId->value()]=$attempt;}

    public function webhookEventExists(string $providerKey,string $eventId):bool
    {
        return isset($this->events[$providerKey.':'.$eventId]);
    }

    public function insertWebhookEvent(
        string $providerKey,string $eventId,EntityId $attemptId,PaymentAttemptState $state,
        string $payloadHash,DateTimeImmutable $occurredAt,DateTimeImmutable $receivedAt
    ):void{
        $this->events[$providerKey.':'.$eventId]=true;
    }

    public function refund(EntityId $refundId,bool $forUpdate=false):?PaymentRefund
    {
        return $this->refunds[$refundId->value()]??null;
    }

    public function refundByIdempotency(EntityId $attemptId,string $idempotencyKey):?PaymentRefund
    {
        foreach($this->refunds as $refund){
            if($refund->attemptId->equals($attemptId)&&$refund->idempotencyKey===$idempotencyKey)return $refund;
        }
        return null;
    }

    public function refundByProviderReference(EntityId $attemptId,string $providerRefundReference):?PaymentRefund
    {
        foreach($this->refunds as $refund){
            if($refund->attemptId->equals($attemptId)&&$refund->providerRefundReference===$providerRefundReference)return $refund;
        }
        return null;
    }

    public function insertRefund(PaymentRefund $refund):void{$this->refunds[$refund->refundId->value()]=$refund;}
    public function saveRefund(PaymentRefund $refund):void{$this->refunds[$refund->refundId->value()]=$refund;}
    public function attempts(int $limit=100):array{return array_slice(array_values($this->attempts),0,$limit);}
    public function refundsForAttempt(EntityId $attemptId):array
    {
        return array_values(array_filter(
            $this->refunds,static fn(PaymentRefund $refund):bool=>$refund->attemptId->equals($attemptId)
        ));
    }
}

final class PaymentMemoryOrderRepository implements MarketplacePurchaseRepository
{
    /** @var array<string,MarketplaceOrder> */
    private array $orders=[];
    /** @var list<array{order:string,action:string}> */
    public array $history=[];

    public function __construct(MarketplaceOrder $order){$this->store($order);}
    public function store(MarketplaceOrder $order):void{$this->orders[$order->orderId->value()]=$order;}

    public function internalSaleEnabled(EntityId $listingId):bool{return false;}
    public function saveInternalSaleSetting(EntityId $listingId,bool $enabled,EntityId $actor,DateTimeImmutable $at):void{}
    public function cartListingIds(EntityId $buyer,bool $forUpdate=false):array{return [];}
    public function cartCount(EntityId $buyer):int{return 0;}
    public function addCartItem(EntityId $buyer,EntityId $listingId,DateTimeImmutable $at):void{}
    public function removeCartItem(EntityId $buyer,EntityId $listingId):void{}
    public function clearCart(EntityId $buyer):void{}
    public function ordersForCheckout(EntityId $buyer,string $checkoutKey):array{return [];}
    public function createOrder(MarketplaceOrder $order,array $items):void{$this->store($order);}
    public function order(EntityId $orderId,bool $forUpdate=false):?MarketplaceOrder{return $this->orders[$orderId->value()]??null;}
    public function orderItems(EntityId $orderId):array{return [];}
    public function ordersForUser(EntityId $userId,int $limit=100):array{return [];}
    public function orders(int $limit=100):array{return array_slice(array_values($this->orders),0,$limit);}
    public function saveOrderStates(MarketplaceOrder $order):void{$this->store($order);}
    public function recordOrderHistory(
        EntityId $orderId,?EntityId $actor,string $action,
        MarketplaceOrderState $fromOrderState,MarketplaceOrderState $toOrderState,
        MarketplacePaymentState $fromPaymentState,MarketplacePaymentState $toPaymentState,
        MarketplaceDeliveryState $fromDeliveryState,MarketplaceDeliveryState $toDeliveryState,
        DateTimeImmutable $at,
    ):void{
        $this->history[]=['order'=>$orderId->value(),'action'=>$action];
    }
}

final class PaymentPermissionRules implements PermissionRuleRepository
{
    /** @param array<string,array<string,bool>> $permissions */
    public function __construct(private array $permissions){}
    public function definition(PermissionKey $key):?PermissionDefinition{return new PermissionDefinition($key,PermissionValueType::Flag);}
    public function rules(PermissionKey $key,UserAccessAssignment $assignment,?EntityId $nodeId):array
    {
        $allowed=$this->permissions[$assignment->userId()->value()][$key->value()]??false;
        return [new PermissionRule(
            PermissionSubjectType::User,$assignment->userId(),$allowed?PermissionEffect::Allow:PermissionEffect::Deny
        )];
    }
}

final class PaymentAssignments implements UserAccessAssignmentProvider
{
    /** @var array<string,true> */
    private array $ids;
    /** @param list<string> $ids */
    public function __construct(array $ids){$this->ids=array_fill_keys($ids,true);}
    public function find(EntityId $userId):?UserAccessAssignment
    {
        return isset($this->ids[$userId->value()])
            ?new UserAccessAssignment($userId,EntityId::fromString(str_repeat('f',32)))
            :null;
    }
}

final class PaymentAudit implements AuditRecorder
{
    /** @var list<AuditEvent> */
    public array $events=[];
    public function append(AuditEvent $event):void{$this->events[]=$event;}
    public function mutate(AuditEvent $event,callable $mutation):mixed
    {
        $result=$mutation();$this->events[]=$event;return $result;
    }
}

final class PaymentTestDatabase implements TransactionalQueryExecutor
{
    private int $depth=0;
    public function execute(CompiledQuery $query):int{return 1;}
    public function fetchOne(CompiledQuery $query):?array{return null;}
    public function fetchAll(CompiledQuery $query):array{return [];}
    public function fetchValue(CompiledQuery $query):mixed{return null;}
    public function inTransaction():bool{return $this->depth>0;}
    public function transaction(Closure $callback):mixed
    {
        ++$this->depth;
        try{return $callback($this);}finally{--$this->depth;}
    }
}
