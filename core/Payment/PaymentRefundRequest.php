<?php

declare(strict_types=1);

namespace Forwext\Core\Payment;

use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class PaymentRefundRequest
{
    public function __construct(
        public EntityId $refundId,
        public EntityId $attemptId,
        public string $providerReference,
        public int $amountMinor,
        public string $currency,
        public string $idempotencyKey,
    ){
        foreach([$this->refundId,$this->attemptId] as $id){
            if(preg_match('/^[a-f0-9]{32}$/D',$id->value())!==1)throw new InvalidArgumentException('Payment refund identifier is invalid.');
        }
        if(preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/D',$this->providerReference)!==1){
            throw new InvalidArgumentException('Payment refund provider reference is invalid.');
        }
        if($this->amountMinor<1)throw new InvalidArgumentException('Payment refund amount must be positive.');
        if(preg_match('/^[A-Z]{3}$/D',$this->currency)!==1)throw new InvalidArgumentException('Payment refund currency is invalid.');
        if(preg_match('/^[a-f0-9]{32}$/D',$this->idempotencyKey)!==1){
            throw new InvalidArgumentException('Payment refund idempotency key is invalid.');
        }
    }
}
