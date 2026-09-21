<?php

declare(strict_types=1);

namespace Forwext\Core\Payment;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class PaymentWebhookRequest
{
    public DateTimeImmutable $receivedAt;

    /** @param array<string,list<string>> $headers */
    public function __construct(
        public string $rawBody,
        public array $headers,
        DateTimeImmutable $receivedAt,
    ){
        if(strlen($this->rawBody)>1_048_576)throw new InvalidArgumentException('Payment webhook body is too large.');
        foreach($this->headers as $name=>$values){
            if(!is_string($name)||preg_match("/^[!#$%&'*+.^_\`|~0-9A-Za-z-]+$/D",$name)!==1||!is_array($values)){
                throw new InvalidArgumentException('Payment webhook headers are invalid.');
            }
            foreach($values as $value){
                if(!is_string($value)||strlen($value)>8192||preg_match('/[\r\n\x00]/',$value)===1){
                    throw new InvalidArgumentException('Payment webhook header value is invalid.');
                }
            }
        }
        $this->receivedAt=$receivedAt->setTimezone(new DateTimeZone('UTC'));
    }

    public function firstHeader(string $name):?string
    {
        foreach($this->headers as $key=>$values){
            if(strcasecmp($key,$name)===0)return $values[0]??null;
        }
        return null;
    }
}
