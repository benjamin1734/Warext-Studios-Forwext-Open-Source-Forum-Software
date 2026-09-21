<?php

declare(strict_types=1);

namespace Forwext\App\Web\Advertising;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Audit\HttpAuditRequestId;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Advertising\AdvertisingCampaign;
use Forwext\Core\Advertising\AdvertisingDevice;
use Forwext\Core\Advertising\AdvertisingKind;
use Forwext\Core\Advertising\AdvertisingService;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;

final readonly class AdvertisingManageHandler implements RequestHandlerInterface
{
    public function __construct(
        private AdvertisingService $advertising,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
    ){}

    public function handle(Request $request):Response
    {
        $actor=$this->viewers->resolve($request);
        if($actor===null)return Response::text('Authentication required.',401)->withHeader('Cache-Control','no-store');

        try{
            if($request->method()===HttpMethod::Post){
                $this->save($actor,$request);
                return Response::redirect(
                    $this->basePath->prepend('/admin/advertising?updated=1'),303
                )->withHeader('Cache-Control','no-store');
            }

            $csrf=$request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
            if(!is_string($csrf)||$csrf===''){
                return Response::text('Internal Server Error',500)->withHeader('Cache-Control','no-store');
            }

            $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
            $snapshot=$this->advertising->managementSnapshot($actor,$now->modify('-30 days'),$now);
            $selected=null;$routes=[];$forums=[];$groups=[];$devices=[];
            $raw=$request->query()['id']??null;
            if(is_string($raw)&&$raw!==''){
                $selected=$this->advertising->editableCampaign($actor,self::id($raw));
                if($selected===null)return Response::text('Not Found',404)->withHeader('Cache-Control','no-store');
                $routes=$this->advertising->routeTargets($selected->campaignId);
                $forums=$this->advertising->forumTargets($selected->campaignId);
                $groups=$this->advertising->groupTargets($selected->campaignId);
                $devices=$this->advertising->deviceTargets($selected->campaignId);
            }

            return Response::html(AdvertisingHtml::manage(
                $snapshot['campaigns'],$snapshot['analytics'],$snapshot['placements'],$snapshot['groups'],$snapshot['forums'],
                $selected,$routes,$forums,$groups,$devices,
                $this->advertising->allows($actor,'ads.manage'),
                $this->advertising->allows($actor,'notice.manage'),
                $this->basePath,$csrf,($request->query()['updated']??null)==='1'
            ))->withHeader('Cache-Control','private, no-store')
                ->withHeader('X-Robots-Tag','noindex,nofollow');
        }catch(PermissionDeniedException){
            return Response::text('Forbidden',403)->withHeader('Cache-Control','no-store');
        }catch(InvalidArgumentException){
            return Response::text('Bad Request',400)->withHeader('Cache-Control','no-store');
        }
    }

    private function save(EntityId $actor,Request $request):void
    {
        $body=$request->parsedBody();
        if(($body['action']??null)!=='save')throw new InvalidArgumentException('Advertising action is invalid.');

        $rawId=self::optional($body,'campaign_id',32);
        $existing=$rawId===null?null:$this->advertising->editableCampaign($actor,self::id($rawId));
        if($rawId!==null&&$existing===null)throw new InvalidArgumentException('Advertising campaign was not found.');

        $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
        $campaign=new AdvertisingCampaign(
            $existing?->campaignId??AdvertisingCampaign::generateId(),
            strtolower(self::required($body,'key',64)),
            AdvertisingKind::from(self::required($body,'kind',24)),
            self::required($body,'name',120),
            self::required($body,'headline',160),
            self::optional($body,'body',4000)??'',
            self::optional($body,'destination_url',2048),
            self::required($body,'placement_key',64),
            self::checked($body,'enabled'),
            self::integer($body,'priority',-32768,32767,0),
            self::optionalInteger($body,'frequency_cap',1,100000),
            self::optionalInteger($body,'frequency_window_seconds',60,2678400),
            self::money(self::required($body,'impression_value',32)),
            self::money(self::required($body,'click_value',32)),
            strtoupper(self::required($body,'currency',3)),
            self::time(self::optional($body,'starts_at',32)),
            self::time(self::optional($body,'ends_at',32)),
            $existing?->createdAt??$now,
            $now
        );

        $this->advertising->saveCampaign(
            $actor,$campaign,
            self::routePatterns($body['route_targets']??''),
            self::ids($body['forum_ids']??[]),
            self::ids($body['group_ids']??[]),
            self::devices($body['device_targets']??[]),
            HttpAuditRequestId::fromRequest($request)
        );
    }

    private static function id(string $raw):EntityId
    {
        if(preg_match('/^[a-f0-9]{32}$/D',$raw)!==1)throw new InvalidArgumentException('Advertising id is invalid.');
        return EntityId::fromString($raw);
    }

    /** @param array<string,mixed> $body */
    private static function required(array $body,string $key,int $max):string
    {
        $value=$body[$key]??null;
        if(!is_string($value)||trim($value)===''||strlen(trim($value))>$max){
            throw new InvalidArgumentException('Advertising field is invalid: '.$key);
        }
        return trim($value);
    }

    /** @param array<string,mixed> $body */
    private static function optional(array $body,string $key,int $max):?string
    {
        $value=$body[$key]??null;
        if($value===null||$value==='')return null;
        if(!is_string($value)||strlen($value)>$max)throw new InvalidArgumentException('Advertising optional field is invalid.');
        $value=trim($value);
        return $value===''?null:$value;
    }

    /** @param array<string,mixed> $body */
    private static function checked(array $body,string $key):bool
    {
        return ($body[$key]??null)==='1'||($body[$key]??null)===1||($body[$key]??null)===true;
    }

    /** @param array<string,mixed> $body */
    private static function integer(array $body,string $key,int $min,int $max,int $default):int
    {
        if(!array_key_exists($key,$body)||$body[$key]==='')return $default;
        $value=filter_var($body[$key],FILTER_VALIDATE_INT);
        if(!is_int($value)||$value<$min||$value>$max)throw new InvalidArgumentException('Advertising integer field is invalid.');
        return $value;
    }

    /** @param array<string,mixed> $body */
    private static function optionalInteger(array $body,string $key,int $min,int $max):?int
    {
        if(!array_key_exists($key,$body)||$body[$key]===''||$body[$key]===null)return null;
        $value=filter_var($body[$key],FILTER_VALIDATE_INT);
        if(!is_int($value)||$value<$min||$value>$max)throw new InvalidArgumentException('Advertising optional integer field is invalid.');
        return $value;
    }

    private static function money(string $value):int
    {
        if(preg_match('/^(\d{1,13})(?:\.(\d{1,2}))?$/D',$value,$match)!==1){
            throw new InvalidArgumentException('Advertising monetary value is invalid.');
        }
        $major=(int)$match[1];
        if($major>intdiv(PHP_INT_MAX,100))throw new InvalidArgumentException('Advertising monetary value is too large.');
        return $major*100+(int)str_pad($match[2]??'',2,'0');
    }

    private static function time(?string $value):?DateTimeImmutable
    {
        if($value===null)return null;
        $parsed=DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i',$value,new DateTimeZone('UTC'));
        if(!$parsed instanceof DateTimeImmutable)throw new InvalidArgumentException('Advertising UTC date/time is invalid.');
        return $parsed;
    }

    /** @return list<string> */
    private static function routePatterns(mixed $value):array
    {
        if(!is_string($value)||strlen($value)>20000)throw new InvalidArgumentException('Advertising route targets are invalid.');
        $result=[];
        foreach(preg_split('/\R/u',$value)?:[] as $line){
            $line=trim($line);
            if($line!=='')$result[$line]=true;
        }
        if(count($result)>100)throw new InvalidArgumentException('Too many advertising route targets.');
        return array_keys($result);
    }

    /** @return list<EntityId> */
    private static function ids(mixed $values):array
    {
        if($values===null||$values==='')return [];
        if(!is_array($values)||count($values)>200)throw new InvalidArgumentException('Advertising target id list is invalid.');
        $result=[];
        foreach($values as $value){
            if(!is_string($value))throw new InvalidArgumentException('Advertising target id is invalid.');
            $id=EntityId::fromString($value);
            $result[$id->value()]=$id;
        }
        return array_values($result);
    }

    /** @return list<AdvertisingDevice> */
    private static function devices(mixed $values):array
    {
        if($values===null||$values==='')return [];
        if(!is_array($values)||count($values)>2)throw new InvalidArgumentException('Advertising device target list is invalid.');
        $result=[];
        foreach($values as $value){
            if(!is_string($value))throw new InvalidArgumentException('Advertising device target is invalid.');
            $device=AdvertisingDevice::from($value);
            $result[$device->value]=$device;
        }
        return array_values($result);
    }
}
