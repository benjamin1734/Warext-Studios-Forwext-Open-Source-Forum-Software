<?php

declare(strict_types=1);

namespace Forwext\Core\Payment;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class DatabasePaymentRepository implements PaymentRepository
{
    public function __construct(private TransactionalQueryExecutor $database){}

    public function attempt(EntityId $attemptId,bool $forUpdate=false):?PaymentAttempt
    {
        $row=$this->database->fetchOne(new CompiledQuery(
            'SELECT * FROM forwext_payment_attempts WHERE attempt_id=:id LIMIT 1'.($forUpdate?' FOR UPDATE':''),
            ['id'=>$attemptId->value()]
        ));
        return $row===null?null:$this->hydrateAttempt($row);
    }

    public function attemptByIdempotency(EntityId $orderId,string $providerKey,string $idempotencyKey):?PaymentAttempt
    {
        $row=$this->database->fetchOne(new CompiledQuery(
            'SELECT * FROM forwext_payment_attempts WHERE order_id=:order_id AND provider_key=:provider '
            . 'AND idempotency_key=:idempotency LIMIT 1',
            ['order_id'=>$orderId->value(),'provider'=>$providerKey,'idempotency'=>$idempotencyKey]
        ));
        return $row===null?null:$this->hydrateAttempt($row);
    }

    public function attemptByProviderReference(string $providerKey,string $providerReference):?PaymentAttempt
    {
        $row=$this->database->fetchOne(new CompiledQuery(
            'SELECT * FROM forwext_payment_attempts WHERE provider_key=:provider AND provider_reference=:reference LIMIT 1',
            ['provider'=>$providerKey,'reference'=>$providerReference]
        ));
        return $row===null?null:$this->hydrateAttempt($row);
    }

    public function insertAttempt(PaymentAttempt $attempt):void
    {
        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_payment_attempts '
            . '(attempt_id,order_id,buyer_user_id,provider_key,idempotency_key,amount_minor,currency,state,'
            . 'provider_reference,checkout_url,error_code,created_at_utc,updated_at_utc) '
            . 'VALUES (:id,:order_id,:buyer,:provider,:idempotency,:amount,:currency,:state,:reference,:checkout_url,:error_code,:created,:updated)',
            self::attemptParams($attempt)
        ));
    }

    public function saveAttempt(PaymentAttempt $attempt):void
    {
        $params=self::attemptParams($attempt);
        $this->database->execute(new CompiledQuery(
            'UPDATE forwext_payment_attempts SET state=:state,provider_reference=:reference,checkout_url=:checkout_url,'
            . 'error_code=:error_code,updated_at_utc=:updated WHERE attempt_id=:id',
            $params
        ));
    }

    public function webhookEventExists(string $providerKey,string $eventId):bool
    {
        return (int)$this->database->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM forwext_payment_webhook_events WHERE provider_key=:provider AND event_id=:event_id',
            ['provider'=>$providerKey,'event_id'=>$eventId]
        ))>0;
    }

    public function insertWebhookEvent(
        string $providerKey,string $eventId,EntityId $attemptId,PaymentAttemptState $state,
        string $payloadHash,DateTimeImmutable $occurredAt,DateTimeImmutable $receivedAt
    ):void{
        if(preg_match('/^[a-f0-9]{64}$/D',$payloadHash)!==1)throw new InvalidArgumentException('Payment webhook payload hash is invalid.');
        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_payment_webhook_events '
            . '(provider_key,event_id,attempt_id,state,payload_sha256,occurred_at_utc,received_at_utc) '
            . 'VALUES (:provider,:event_id,:attempt_id,:state,:payload_hash,:occurred,:received)',
            [
                'provider'=>$providerKey,'event_id'=>$eventId,'attempt_id'=>$attemptId->value(),'state'=>$state->value,
                'payload_hash'=>$payloadHash,'occurred'=>self::format($occurredAt),'received'=>self::format($receivedAt),
            ]
        ));
    }

    public function refund(EntityId $refundId,bool $forUpdate=false):?PaymentRefund
    {
        $row=$this->database->fetchOne(new CompiledQuery(
            'SELECT * FROM forwext_payment_refunds WHERE refund_id=:id LIMIT 1'.($forUpdate?' FOR UPDATE':''),
            ['id'=>$refundId->value()]
        ));
        return $row===null?null:$this->hydrateRefund($row);
    }

    public function refundByIdempotency(EntityId $attemptId,string $idempotencyKey):?PaymentRefund
    {
        $row=$this->database->fetchOne(new CompiledQuery(
            'SELECT * FROM forwext_payment_refunds WHERE attempt_id=:attempt_id AND idempotency_key=:idempotency LIMIT 1',
            ['attempt_id'=>$attemptId->value(),'idempotency'=>$idempotencyKey]
        ));
        return $row===null?null:$this->hydrateRefund($row);
    }

    public function insertRefund(PaymentRefund $refund):void
    {
        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_payment_refunds '
            . '(refund_id,attempt_id,actor_user_id,idempotency_key,amount_minor,state,provider_refund_reference,error_code,created_at_utc,updated_at_utc) '
            . 'VALUES (:id,:attempt_id,:actor,:idempotency,:amount,:state,:reference,:error_code,:created,:updated)',
            self::refundParams($refund)
        ));
    }

    public function saveRefund(PaymentRefund $refund):void
    {
        $this->database->execute(new CompiledQuery(
            'UPDATE forwext_payment_refunds SET state=:state,provider_refund_reference=:reference,error_code=:error_code,'
            . 'updated_at_utc=:updated WHERE refund_id=:id',
            self::refundParams($refund)
        ));
    }

    public function attempts(int $limit=100):array
    {
        if($limit<1||$limit>200)throw new InvalidArgumentException('Payment attempt list limit is invalid.');
        $rows=$this->database->fetchAll(new CompiledQuery(
            'SELECT * FROM forwext_payment_attempts ORDER BY created_at_utc DESC,attempt_id DESC LIMIT '.$limit
        ));
        return array_map($this->hydrateAttempt(...),$rows);
    }

    public function refundsForAttempt(EntityId $attemptId):array
    {
        $rows=$this->database->fetchAll(new CompiledQuery(
            'SELECT * FROM forwext_payment_refunds WHERE attempt_id=:attempt_id ORDER BY created_at_utc,refund_id',
            ['attempt_id'=>$attemptId->value()]
        ));
        return array_map($this->hydrateRefund(...),$rows);
    }

    /** @param array<string,mixed> $row */
    private function hydrateAttempt(array $row):PaymentAttempt
    {
        return new PaymentAttempt(
            EntityId::fromString((string)$row['attempt_id']),EntityId::fromString((string)$row['order_id']),
            EntityId::fromString((string)$row['buyer_user_id']),(string)$row['provider_key'],(string)$row['idempotency_key'],
            (int)$row['amount_minor'],(string)$row['currency'],PaymentAttemptState::from((string)$row['state']),
            self::nullable($row['provider_reference']??null),self::nullable($row['checkout_url']??null),
            self::nullable($row['error_code']??null),self::at((string)$row['created_at_utc']),self::at((string)$row['updated_at_utc'])
        );
    }

    /** @param array<string,mixed> $row */
    private function hydrateRefund(array $row):PaymentRefund
    {
        return new PaymentRefund(
            EntityId::fromString((string)$row['refund_id']),EntityId::fromString((string)$row['attempt_id']),
            EntityId::fromString((string)$row['actor_user_id']),(string)$row['idempotency_key'],(int)$row['amount_minor'],
            PaymentRefundState::from((string)$row['state']),self::nullable($row['provider_refund_reference']??null),
            self::nullable($row['error_code']??null),self::at((string)$row['created_at_utc']),self::at((string)$row['updated_at_utc'])
        );
    }

    /** @return array<string,mixed> */
    private static function attemptParams(PaymentAttempt $attempt):array
    {
        return [
            'id'=>$attempt->attemptId->value(),'order_id'=>$attempt->orderId->value(),'buyer'=>$attempt->buyerUserId->value(),
            'provider'=>$attempt->providerKey,'idempotency'=>$attempt->idempotencyKey,'amount'=>$attempt->amountMinor,
            'currency'=>$attempt->currency,'state'=>$attempt->state->value,'reference'=>$attempt->providerReference,
            'checkout_url'=>$attempt->checkoutUrl,'error_code'=>$attempt->errorCode,
            'created'=>self::format($attempt->createdAt),'updated'=>self::format($attempt->updatedAt),
        ];
    }

    /** @return array<string,mixed> */
    private static function refundParams(PaymentRefund $refund):array
    {
        return [
            'id'=>$refund->refundId->value(),'attempt_id'=>$refund->attemptId->value(),'actor'=>$refund->actorUserId->value(),
            'idempotency'=>$refund->idempotencyKey,'amount'=>$refund->amountMinor,'state'=>$refund->state->value,
            'reference'=>$refund->providerRefundReference,'error_code'=>$refund->errorCode,
            'created'=>self::format($refund->createdAt),'updated'=>self::format($refund->updatedAt),
        ];
    }

    private static function nullable(mixed $value):?string{return $value===null?null:(string)$value;}
    private static function at(string $value):DateTimeImmutable{return new DateTimeImmutable($value,new DateTimeZone('UTC'));}
    private static function format(DateTimeImmutable $value):string{return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');}
}
