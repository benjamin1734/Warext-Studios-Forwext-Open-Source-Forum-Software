<?php

declare(strict_types=1);

namespace Forwext\Core\Marketplace;

use InvalidArgumentException;

final readonly class MarketplaceBillingSnapshot
{
    public string $name;
    public string $email;
    public string $countryCode;
    public ?string $taxId;
    public ?string $addressLine;

    public function __construct(string $name,string $email,string $countryCode,?string $taxId=null,?string $addressLine=null)
    {
        $name=trim($name);$email=strtolower(trim($email));$countryCode=strtoupper(trim($countryCode));
        $taxId=self::optional($taxId,64);$addressLine=self::optional($addressLine,1000);
        if(strlen($name)<2||strlen($name)>160||preg_match('//u',$name)!==1||preg_match('/[\x00-\x1F\x7F]/u',$name)===1){
            throw new InvalidArgumentException('Marketplace billing name is invalid.');
        }
        if(strlen($email)>254||filter_var($email,FILTER_VALIDATE_EMAIL)===false){
            throw new InvalidArgumentException('Marketplace billing email is invalid.');
        }
        if(preg_match('/^[A-Z]{2}$/D',$countryCode)!==1){
            throw new InvalidArgumentException('Marketplace billing country code is invalid.');
        }
        $this->name=$name;$this->email=$email;$this->countryCode=$countryCode;
        $this->taxId=$taxId;$this->addressLine=$addressLine;
    }

    /** @return array{name:string,email:string,country_code:string,tax_id:?string,address_line:?string} */
    public function toArray():array
    {
        return [
            'name'=>$this->name,'email'=>$this->email,'country_code'=>$this->countryCode,
            'tax_id'=>$this->taxId,'address_line'=>$this->addressLine,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data):self
    {
        return new self(
            self::requiredString($data,'name'),
            self::requiredString($data,'email'),
            self::requiredString($data,'country_code'),
            self::nullableString($data,'tax_id'),
            self::nullableString($data,'address_line'),
        );
    }

    private static function optional(?string $value,int $max):?string
    {
        if($value===null)return null;
        $value=trim($value);
        if($value==='')return null;
        if(strlen($value)>$max||preg_match('//u',$value)!==1||preg_match('/[\x00-\x1F\x7F]/u',$value)===1){
            throw new InvalidArgumentException('Marketplace billing metadata is invalid.');
        }
        return $value;
    }

    /** @param array<string,mixed> $data */
    private static function requiredString(array $data,string $key):string
    {
        $value=$data[$key]??null;
        if(!is_string($value))throw new InvalidArgumentException('Marketplace billing snapshot is invalid.');
        return $value;
    }

    /** @param array<string,mixed> $data */
    private static function nullableString(array $data,string $key):?string
    {
        $value=$data[$key]??null;
        if($value===null)return null;
        if(!is_string($value))throw new InvalidArgumentException('Marketplace billing snapshot is invalid.');
        return $value;
    }
}
