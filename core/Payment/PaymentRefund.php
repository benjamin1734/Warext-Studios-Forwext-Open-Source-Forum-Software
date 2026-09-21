<?php

declare(strict_types=1);

namespace Forwext\Core\Payment;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class PaymentRefund
{
    public DateTimeImmutable $createdAt;
    public DateTimeImmutable $updatedAt;

    public function __construct(
        public EntityId $refundId,
        public EntityId $attemptId,
        public EntityId $actorUserId,
        public string $idempotencyKey,
        public int $amountMinor,
        public PaymentRefundState $state,
        public ?string $providerRefundReference,
        public ?string $errorCode,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ){
        foreach([$this->refundId,$this->attemptId] as $id){
            if(preg_match('/^[a-f0-9]{32}$/D',$id->value())!==1)throw new InvalidArgumentException('Payment refund identifier is invalid.');
        }
        UserId::assert($this->actorUserId);
        if(preg_match('/^[a-f0-9]{32}$/D',$this->idempotencyKey)!==1||$this->amountMinor<1){
            throw new InvalidArgumentException('Payment refund idempotency or amount is invalid.');
        }
        if($this->providerRefundReference!==null
            &&preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/D',$this->providerRefundReference)!==1
        ){
            throw new InvalidArgumentException('Payment provider refund reference is invalid.');
        }
        if($this->errorCode!==null&&preg_match('/^[a-z][a-z0-9._-]{0,63}$/D',$this->errorCode)!==1){
            throw new InvalidArgumentException('Payment refund error code is invalid.');
        }
        $utc=new DateTimeZone('UTC');
        $this->createdAt=$createdAt->setTimezone($utc);$this->updatedAt=$updatedAt->setTimezone($utc);
        if($this->updatedAt<$this->createdAt)throw new InvalidArgumentException('Payment refund timestamps are invalid.');
    }

    public static function generateId():EntityId{return EntityId::fromString(bin2hex(random_bytes(16)));}
}
