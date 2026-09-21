<?php

declare(strict_types=1);

namespace Forwext\Core\Payment;

use InvalidArgumentException;

final class PaymentProviderRegistry
{
    /** @var array<string,PaymentProvider> */
    private array $providers=[];

    /** @param iterable<PaymentProvider> $providers */
    public function __construct(iterable $providers=[])
    {
        foreach($providers as $provider)$this->register($provider);
    }

    public function register(PaymentProvider $provider):void
    {
        $key=$provider->key();
        if(preg_match('/^[a-z][a-z0-9._-]{1,63}$/D',$key)!==1||isset($this->providers[$key])){
            throw new InvalidArgumentException('Payment provider key is invalid or duplicated.');
        }
        $this->providers[$key]=$provider;
    }

    public function find(string $key):?PaymentProvider{return $this->providers[$key]??null;}

    public function require(string $key):PaymentProvider
    {
        return $this->providers[$key]??throw new InvalidArgumentException('Payment provider is not registered.');
    }

    /** @return list<string> */
    public function keys():array
    {
        $keys=array_keys($this->providers);sort($keys,SORT_STRING);return $keys;
    }
}
