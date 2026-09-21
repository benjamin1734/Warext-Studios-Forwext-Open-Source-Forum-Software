<?php

declare(strict_types=1);

namespace Forwext\Core\Payment;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class PaymentWebhookEvent
{
    public DateTimeImmutable $occurredAt;

    public function __construct(
        public string $eventId,
        public PaymentAttemptState $state,
        public ?EntityId $attemptId,
        public ?string $providerReference,
        public ?int $amountMinor,
        public ?string $currency,
        DateTimeImmutable $occurredAt,
    ){
        if(preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/D',$this->eventId)!==1){
            throw new InvalidArgumentException('Payment webhook event id is invalid.');
        }
        if($this->attemptId!==null&&preg_match('/^[a-f0-9]{32}$/D',$this->attemptId->value())!==1){
            throw new InvalidArgumentException('Payment webhook attempt id is invalid.');
        }
        if($this->providerReference!==null
            &&preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/D',$this->providerReference)!==1
        ){
            throw new InvalidArgumentException('Payment webhook provider reference is invalid.');
        }
        if($this->attemptId===null&&$this->providerReference===null){
            throw new InvalidArgumentException('Payment webhook event must identify an attempt.');
        }
        if($this->amountMinor!==null&&$this->amountMinor<0)throw new InvalidArgumentException('Payment webhook amount is invalid.');
        if($this->currency!==null&&preg_match('/^[A-Z]{3}$/D',$this->currency)!==1){
            throw new InvalidArgumentException('Payment webhook currency is invalid.');
        }
        $this->occurredAt=$occurredAt->setTimezone(new DateTimeZone('UTC'));
    }
}
