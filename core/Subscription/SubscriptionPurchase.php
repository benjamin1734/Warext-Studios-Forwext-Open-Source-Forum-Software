<?php

declare(strict_types=1);

namespace Forwext\Core\Subscription;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Payment\PaymentAttemptState;
use InvalidArgumentException;

final readonly class SubscriptionPurchase
{
    public DateTimeImmutable $createdAt;
    public DateTimeImmutable $updatedAt;
    public ?DateTimeImmutable $activatedAt;

    public function __construct(
        public EntityId $purchaseId,
        public EntityId $userId,
        public EntityId $planId,
        public string $providerKey,
        public string $idempotencyKey,
        public int $amountMinor,
        public string $currency,
        public ?int $durationDays,
        public PaymentAttemptState $state,
        public ?string $providerReference,
        public ?string $checkoutUrl,
        public ?string $errorCode,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
        ?DateTimeImmutable $activatedAt=null,
    ){
        foreach([$this->purchaseId,$this->planId] as $id){
            if(preg_match('/^[a-f0-9]{32}$/D',$id->value())!==1){
                throw new InvalidArgumentException('Subscription purchase identifier is invalid.');
            }
        }
        UserId::assert($this->userId);
        if(preg_match('/^[a-z][a-z0-9._-]{1,63}$/D',$this->providerKey)!==1
            ||preg_match('/^[a-f0-9]{32}$/D',$this->idempotencyKey)!==1
            ||$this->amountMinor<1
            ||preg_match('/^[A-Z]{3}$/D',$this->currency)!==1
        ){
            throw new InvalidArgumentException('Subscription purchase payment metadata is invalid.');
        }
        if($this->durationDays!==null&&($this->durationDays<1||$this->durationDays>3650)){
            throw new InvalidArgumentException('Subscription purchase duration snapshot is invalid.');
        }
        if($this->providerReference!==null
            &&preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/D',$this->providerReference)!==1
        ){
            throw new InvalidArgumentException('Subscription purchase provider reference is invalid.');
        }
        if(in_array($this->state,[PaymentAttemptState::RequiresAction,PaymentAttemptState::Authorized,PaymentAttemptState::Paid],true)
            &&$this->providerReference===null
        ){
            throw new InvalidArgumentException('Subscription purchase state requires a provider reference.');
        }
        if($this->checkoutUrl!==null){
            if($this->state!==PaymentAttemptState::RequiresAction
                ||filter_var($this->checkoutUrl,FILTER_VALIDATE_URL)===false
                ||strlen($this->checkoutUrl)>2048
                ||preg_match('/[\x00-\x20\x7F]/',$this->checkoutUrl)===1
            ){
                throw new InvalidArgumentException('Subscription purchase checkout URL is invalid.');
            }
            $parts=parse_url($this->checkoutUrl);
            if(!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'||!isset($parts['host'])
                ||isset($parts['user'])||isset($parts['pass'])
            ){
                throw new InvalidArgumentException('Subscription checkout URL must be HTTPS without credentials.');
            }
        }elseif($this->state===PaymentAttemptState::RequiresAction){
            throw new InvalidArgumentException('Subscription purchase action state requires a checkout URL.');
        }
        if($this->errorCode!==null&&preg_match('/^[a-z][a-z0-9._-]{0,63}$/D',$this->errorCode)!==1){
            throw new InvalidArgumentException('Subscription purchase error code is invalid.');
        }
        $utc=new DateTimeZone('UTC');
        $this->createdAt=$createdAt->setTimezone($utc);
        $this->updatedAt=$updatedAt->setTimezone($utc);
        $this->activatedAt=$activatedAt?->setTimezone($utc);
        if($this->updatedAt<$this->createdAt||($this->activatedAt!==null&&$this->activatedAt<$this->createdAt)){
            throw new InvalidArgumentException('Subscription purchase timestamps are invalid.');
        }
    }

    public static function generateId():EntityId
    {
        return EntityId::fromString(bin2hex(random_bytes(16)));
    }
}
