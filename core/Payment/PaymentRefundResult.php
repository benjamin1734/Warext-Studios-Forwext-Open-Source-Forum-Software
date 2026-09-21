<?php

declare(strict_types=1);

namespace Forwext\Core\Payment;

use InvalidArgumentException;

final readonly class PaymentRefundResult
{
    public function __construct(
        public PaymentRefundState $state,
        public string $providerRefundReference,
        public ?string $errorCode=null,
    ){
        if(preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/D',$this->providerRefundReference)!==1){
            throw new InvalidArgumentException('Payment provider refund reference is invalid.');
        }
        if($this->errorCode!==null&&preg_match('/^[a-z][a-z0-9._-]{0,63}$/D',$this->errorCode)!==1){
            throw new InvalidArgumentException('Payment refund error code is invalid.');
        }
    }
}
