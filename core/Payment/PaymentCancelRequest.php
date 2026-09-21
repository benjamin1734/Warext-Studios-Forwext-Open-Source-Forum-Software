<?php

declare(strict_types=1);

namespace Forwext\Core\Payment;

use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class PaymentCancelRequest
{
    public function __construct(
        public EntityId $attemptId,
        public string $providerReference,
        public string $idempotencyKey,
    ){
        if(preg_match('/^[a-f0-9]{32}$/D',$this->attemptId->value())!==1){
            throw new InvalidArgumentException('Payment cancel attempt id is invalid.');
        }
        if(preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/D',$this->providerReference)!==1){
            throw new InvalidArgumentException('Payment cancel provider reference is invalid.');
        }
        if(preg_match('/^[a-f0-9]{32}$/D',$this->idempotencyKey)!==1){
            throw new InvalidArgumentException('Payment cancel idempotency key is invalid.');
        }
    }
}
