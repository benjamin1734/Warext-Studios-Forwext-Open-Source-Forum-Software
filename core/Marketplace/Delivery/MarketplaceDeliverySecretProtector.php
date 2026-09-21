<?php

declare(strict_types=1);

namespace Forwext\Core\Marketplace\Delivery;

use Forwext\Core\Security\Secret\SecretCipher;
use Forwext\Core\Security\Secret\SecretKey;
use InvalidArgumentException;
use SensitiveParameter;

final readonly class MarketplaceDeliverySecretProtector
{
    public function __construct(
        private SecretCipher $cipher,
        private SecretKey $fingerprintKey,
    ){}

    public function protect(#[SensitiveParameter] string $value):string
    {
        $value=self::normalize($value);
        return $this->cipher->encrypt($value);
    }

    public function reveal(#[SensitiveParameter] string $encrypted):string
    {
        return $this->cipher->decrypt($encrypted);
    }

    public function fingerprint(#[SensitiveParameter] string $value):string
    {
        $value=self::normalize($value);
        return hash_hmac('sha256','marketplace-delivery\0'.$value,$this->fingerprintKey->bytesForCrypto());
    }

    private static function normalize(string $value):string
    {
        $value=trim($value);
        if($value===''||strlen($value)>16384||preg_match('//u',$value)!==1
            ||preg_match('/[\x00\x01-\x08\x0B\x0C\x0E-\x1F\x7F]/',$value)===1
        ){
            throw new InvalidArgumentException('Marketplace delivery secret is invalid.');
        }
        return $value;
    }
}
