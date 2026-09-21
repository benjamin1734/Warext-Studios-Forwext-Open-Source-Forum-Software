<?php

declare(strict_types=1);

namespace Forwext\Core\Payment;

use InvalidArgumentException;

final readonly class PaymentProviderResult
{
    public function __construct(
        public PaymentAttemptState $state,
        public string $providerReference,
        public ?string $checkoutUrl=null,
        public ?string $errorCode=null,
    ){
        if(preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/D',$this->providerReference)!==1){
            throw new InvalidArgumentException('Payment provider reference is invalid.');
        }
        if($this->checkoutUrl!==null)self::assertCheckoutUrl($this->checkoutUrl);
        if($this->errorCode!==null
            &&preg_match('/^[a-z][a-z0-9._-]{0,63}$/D',$this->errorCode)!==1
        ){
            throw new InvalidArgumentException('Payment provider error code is invalid.');
        }
        if($this->state===PaymentAttemptState::RequiresAction&&$this->checkoutUrl===null){
            throw new InvalidArgumentException('Payment action state requires a checkout URL.');
        }
    }

    private static function assertCheckoutUrl(string $url):void
    {
        if(strlen($url)>2048||filter_var($url,FILTER_VALIDATE_URL)===false
            ||preg_match('/[\x00-\x20\x7F]/',$url)===1
        ){
            throw new InvalidArgumentException('Payment checkout URL is invalid.');
        }
        $parts=parse_url($url);
        if(!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'||!isset($parts['host'])
            ||isset($parts['user'])||isset($parts['pass'])
        ){
            throw new InvalidArgumentException('Payment checkout URL must be HTTPS without credentials.');
        }
    }
}
