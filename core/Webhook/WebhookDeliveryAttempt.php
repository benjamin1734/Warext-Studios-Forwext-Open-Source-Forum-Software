<?php

declare(strict_types=1);

namespace Forwext\Core\Webhook;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class WebhookDeliveryAttempt
{
    public DateTimeImmutable $startedAt;
    public DateTimeImmutable $finishedAt;

    public function __construct(
        public string $deliveryId,
        public int $attemptNumber,
        public string $result,
        public ?int $httpStatus,
        public ?string $errorCode,
        public bool $retryable,
        DateTimeImmutable $startedAt,
        DateTimeImmutable $finishedAt,
        public int $durationMs,
    ){
        if(preg_match('/^[a-f0-9]{32}$/D',$this->deliveryId)!==1
            ||$this->attemptNumber<1||$this->attemptNumber>20
            ||preg_match('/^[a-z0-9._-]{1,24}$/D',$this->result)!==1
            ||($this->httpStatus!==null&&($this->httpStatus<100||$this->httpStatus>599))
            ||($this->errorCode!==null&&preg_match('/^[a-z0-9._-]{1,64}$/D',$this->errorCode)!==1)
            ||$this->durationMs<0
        ){
            throw new InvalidArgumentException('Webhook delivery attempt metadata is invalid.');
        }
        $utc=new DateTimeZone('UTC');
        $this->startedAt=$startedAt->setTimezone($utc);
        $this->finishedAt=$finishedAt->setTimezone($utc);
    }
}
