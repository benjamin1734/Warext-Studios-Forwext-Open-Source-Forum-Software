<?php

declare(strict_types=1);

namespace Forwext\Core\Payment;

use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class PaymentCreateRequest
{
    public function __construct(
        public EntityId $attemptId,
        public EntityId $orderId,
        public EntityId $buyerUserId,
        public int $amountMinor,
        public string $currency,
        public string $idempotencyKey,
        public string $returnPath,
        public string $cancelPath,
    ){
        foreach([$this->attemptId,$this->orderId] as $id){
            if(preg_match('/^[a-f0-9]{32}$/D',$id->value())!==1){
                throw new InvalidArgumentException('Payment identifier is invalid.');
            }
        }
        UserId::assert($this->buyerUserId);
        if($this->amountMinor<1)throw new InvalidArgumentException('Payment amount must be positive.');
        if(preg_match('/^[A-Z]{3}$/D',$this->currency)!==1)throw new InvalidArgumentException('Payment currency is invalid.');
        if(preg_match('/^[a-f0-9]{32}$/D',$this->idempotencyKey)!==1){
            throw new InvalidArgumentException('Payment idempotency key is invalid.');
        }
        self::assertPath($this->returnPath);
        self::assertPath($this->cancelPath);
    }

    private static function assertPath(string $path):void
    {
        if($path===''||strlen($path)>1000||!str_starts_with($path,'/')||str_starts_with($path,'//')
            ||str_contains($path,'\\')||preg_match('/[\x00-\x1F\x7F]/',$path)===1
        ){
            throw new InvalidArgumentException('Payment return path must be a safe same-origin path.');
        }
    }
}
