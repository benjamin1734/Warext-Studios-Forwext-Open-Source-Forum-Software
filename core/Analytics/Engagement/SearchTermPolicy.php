<?php

declare(strict_types=1);

namespace Forwext\Core\Analytics\Engagement;

final class SearchTermPolicy
{
    public function safeDisplay(string $raw):?string
    {
        $value=trim($raw);
        $value=preg_replace('/\s+/u',' ',$value)??'';
        if($value===''||strlen($value)>96||preg_match('//u',$value)!==1)return null;

        $value=strtr($value,[
            'İ'=>'i','Ğ'=>'ğ','Ü'=>'ü','Ş'=>'ş','Ö'=>'ö','Ç'=>'ç',
        ]);
        $value=strtolower($value);

        if(str_contains($value,'@')||str_contains($value,'://')||str_contains($value,'www.')){
            return null;
        }
        if(filter_var($value,FILTER_VALIDATE_IP)!==false)return null;
        if(preg_match('/\d(?:[\s().+\-]*\d){6,}/u',$value)===1)return null;
        if(preg_match('/^[\p{L}\p{N} _.-]{2,64}$/u',$value)!==1)return null;

        preg_match_all('/\d/u',$value,$digits);
        if(count($digits[0]??[])>4)return null;

        return $value;
    }

    public function queryClass(string $raw):string
    {
        $trimmed=trim($raw);
        if($trimmed===''||strlen($trimmed)<2)return 'short';
        return $this->safeDisplay($raw)===null?'redacted':'safe';
    }

    public function resultBucket(int $count):string
    {
        return match(true){
            $count<=0=>'zero',
            $count<=5=>'one_five',
            $count<=20=>'six_twenty',
            default=>'twenty_plus',
        };
    }
}
