<?php

declare(strict_types=1);

namespace Forwext\Core\Payment;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class PaymentAttempt
{
    public DateTimeImmutable $createdAt;
    public DateTimeImmutable $updatedAt;

    public function __construct(
        public EntityId $attemptId,
        public EntityId $orderId,
        public EntityId $buyerUserId,
        public string $providerKey,
        public string $idempotencyKey,
        public int $amountMinor,
        public string $currency,
        public PaymentAttemptState $state,
        public ?string $providerReference,
        public ?string $checkoutUrl,
        public ?string $errorCode,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ){
        foreach([$this->attemptId,$this->orderId] as $id){
            if(preg_match('/^[a-f0-9]{32}$/D',$id->value())!==1)throw new InvalidArgumentException('Payment attempt identifier is invalid.');
        }
        UserId::assert($this->buyerUserId);
        if(preg_match('/^[a-z][a-z0-9._-]{1,63}$/D',$this->providerKey)!==1)throw new InvalidArgumentException('Payment provider key is invalid.');
        if(preg_match('/^[a-f0-9]{32}$/D',$this->idempotencyKey)!==1)throw new InvalidArgumentException('Payment idempotency key is invalid.');
        if($this->amountMinor<1||preg_match('/^[A-Z]{3}$/D',$this->currency)!==1)throw new InvalidArgumentException('Payment attempt amount or currency is invalid.');
        if($this->providerReference!==null&&preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/D',$this->providerReference)!==1){
            throw new InvalidArgumentException('Payment provider reference is invalid.');
        }
        if($this->checkoutUrl!==null){
            new PaymentProviderResult($this->state,$this->providerReference??'pending-reference',$this->checkoutUrl,$this->errorCode);
        }elseif($this->errorCode!==null&&preg_match('/^[a-z][a-z0-9._-]{0,63}$/D',$this->errorCode)!==1){
            throw new InvalidArgumentException('Payment error code is invalid.');
        }
        $utc=new DateTimeZone('UTC');
        $this->createdAt=$createdAt->setTimezone($utc);$this->updatedAt=$updatedAt->setTimezone($utc);
        if($this->updatedAt<$this->createdAt)throw new InvalidArgumentException('Payment attempt timestamps are invalid.');
    }

    public static function generateId():EntityId{return EntityId::fromString(bin2hex(random_bytes(16)));}
}
