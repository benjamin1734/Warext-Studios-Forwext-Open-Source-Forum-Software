<?php

declare(strict_types=1);

namespace Forwext\Core\Marketplace;

use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class MarketplaceExternalSaleUrlPolicy
{
    /** @var list<string> */
    private array $allowedHosts;

    /** @param list<string> $allowedHosts */
    public function __construct(
        array $allowedHosts,
        private bool $allowSubdomains=false,
        private string $utmSource='forwext',
        private string $utmMedium='marketplace',
    ){
        $normalized=[];
        foreach($allowedHosts as $host){
            if(!is_string($host))throw new InvalidArgumentException('Marketplace external-sale allowlist entry is invalid.');
            $normalized[self::normalizeHost($host)]=true;
        }
        $this->allowedHosts=array_keys($normalized);
        self::assertUtm($this->utmSource,'source');
        self::assertUtm($this->utmMedium,'medium');
    }

    /** @return list<string> */
    public function allowedHosts():array{return $this->allowedHosts;}

    /** @return array{url:string,host:string} */
    public function validate(string $url):array
    {
        $url=trim($url);
        if($url===''||strlen($url)>2048||str_contains($url,'\\')||str_contains($url,'#')
            ||preg_match('/[\x00-\x20\x7F]/',$url)===1||filter_var($url,FILTER_VALIDATE_URL)===false
        ){
            throw new InvalidArgumentException('Marketplace external-sale URL is invalid.');
        }
        $parts=parse_url($url);
        if(!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'||!isset($parts['host'])
            ||isset($parts['user'])||isset($parts['pass'])||((int)($parts['port']??443)!==443)
        ){
            throw new InvalidArgumentException('Marketplace external-sale URL must use HTTPS without credentials or a custom port.');
        }
        $host=self::normalizeHost((string)$parts['host']);
        if(filter_var($host,FILTER_VALIDATE_IP)!==false||!str_contains($host,'.')||!$this->isAllowedHost($host)){
            throw new InvalidArgumentException('Marketplace external-sale host is not allowed.');
        }
        return ['url'=>$url,'host'=>$host];
    }

    public function outboundUrl(MarketplaceExternalSaleLink $link,EntityId $listingId):string
    {
        $validated=$this->validate($link->targetUrl);
        if(!hash_equals($validated['host'],$link->targetHost)){
            throw new InvalidArgumentException('Marketplace external-sale host binding is invalid.');
        }
        $url=$validated['url'];
        $query=parse_url($url,PHP_URL_QUERY);
        $raw=is_string($query)?$query:'';
        $append=[];
        if(preg_match('/(?:^|&)utm_source=/i',$raw)!==1)$append['utm_source']=$this->utmSource;
        if(preg_match('/(?:^|&)utm_medium=/i',$raw)!==1)$append['utm_medium']=$this->utmMedium;
        if(preg_match('/(?:^|&)utm_campaign=/i',$raw)!==1)$append['utm_campaign']='listing-'.$listingId->value();
        if($append===[])return $url;
        return $url.($raw===''?'?':'&').http_build_query($append,'','&',PHP_QUERY_RFC3986);
    }

    private function isAllowedHost(string $host):bool
    {
        foreach($this->allowedHosts as $allowed){
            if(hash_equals($allowed,$host))return true;
            if($this->allowSubdomains&&str_ends_with($host,'.'.$allowed))return true;
        }
        return false;
    }

    private static function normalizeHost(string $host):string
    {
        $host=strtolower(rtrim(trim($host),'.'));
        if($host===''||strlen($host)>253||preg_match('/^[a-z0-9.-]+$/D',$host)!==1
            ||filter_var($host,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME)===false
        ){
            throw new InvalidArgumentException('Marketplace external-sale host is invalid.');
        }
        return $host;
    }

    private static function assertUtm(string $value,string $label):void
    {
        if($value===''||strlen($value)>64||preg_match('/^[A-Za-z0-9._-]+$/D',$value)!==1){
            throw new InvalidArgumentException('Marketplace external-sale UTM '.$label.' is invalid.');
        }
    }
}
