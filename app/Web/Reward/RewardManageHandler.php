<?php

declare(strict_types=1);

namespace Forwext\App\Web\Reward;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Audit\HttpAuditRequestId;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Reward\RewardBinding;
use Forwext\Core\Reward\RewardDefinition;
use Forwext\Core\Reward\RewardService;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;

final readonly class RewardManageHandler implements RequestHandlerInterface
{
    public function __construct(
        private RewardService $rewards,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
    ){}

    public function handle(Request $request):Response
    {
        $actor=$this->viewers->resolve($request);
        if($actor===null)return Response::text('Authentication required.',401)->withHeader('Cache-Control','no-store');
        try{
            if($request->method()===HttpMethod::Post){
                $this->mutate($actor,$request);
                return Response::text('',303)
                    ->withHeader('Location',$this->basePath->prepend('/admin/rewards?updated=1'))
                    ->withHeader('Cache-Control','no-store');
            }
            $token=$request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
            if(!is_string($token)||$token==='')return Response::text('Internal Server Error',500)->withHeader('Cache-Control','no-store');
            $snapshot=$this->rewards->managementSnapshot($actor);
            $selectedDefinition=null;
            $rawReward=$request->query()['reward_id']??null;
            if(is_string($rawReward)&&$rawReward!==''){
                $selectedDefinition=$this->rewards->definition($actor,self::id($rawReward));
                if($selectedDefinition===null)return Response::text('Not Found',404)->withHeader('Cache-Control','no-store');
            }
            $selectedBinding=null;
            $rawBinding=$request->query()['binding_id']??null;
            if(is_string($rawBinding)&&$rawBinding!==''){
                $selectedBinding=$this->rewards->binding($actor,self::id($rawBinding));
                if($selectedBinding===null)return Response::text('Not Found',404)->withHeader('Cache-Control','no-store');
            }
            return Response::html(RewardHtml::manage(
                $snapshot,$selectedDefinition,$selectedBinding,$this->basePath,$token,
                ($request->query()['updated']??null)==='1'
            ))->withHeader('Cache-Control','private, no-store');
        }catch(PermissionDeniedException){
            return Response::text('Forbidden',403)->withHeader('Cache-Control','no-store');
        }catch(InvalidArgumentException){
            return Response::text('Bad Request',400)->withHeader('Cache-Control','no-store');
        }
    }

    private function mutate(EntityId $actor,Request $request):void
    {
        $body=$request->parsedBody();
        $action=$body['action']??null;
        if(!is_string($action))throw new InvalidArgumentException('Reward action is missing.');
        $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
        if($action==='save_definition'){
            $raw=self::optional($body,'reward_id',32);
            $existing=$raw===null?null:$this->rewards->definition($actor,self::id($raw));
            if($raw!==null&&$existing===null)throw new InvalidArgumentException('Reward definition does not exist.');
            [$provider,$target]=self::target(self::required($body,'provider_target',100));
            $definition=new RewardDefinition(
                $existing?->rewardId??RewardDefinition::generateId(),
                strtolower(self::required($body,'key',64)),
                self::required($body,'name',120),
                self::checked($body,'active'),
                $provider,
                self::id($target),
                $existing?->createdAt??$now,
                $now,
            );
            $this->rewards->saveDefinition($actor,$definition,$now,HttpAuditRequestId::fromRequest($request));
            return;
        }
        if($action==='save_binding'){
            $raw=self::optional($body,'binding_id',32);
            $existing=$raw===null?null:$this->rewards->binding($actor,self::id($raw));
            if($raw!==null&&$existing===null)throw new InvalidArgumentException('Reward binding does not exist.');
            [$sourceType,$sourceId]=self::source(self::required($body,'source',80));
            $binding=new RewardBinding(
                $existing?->bindingId??RewardBinding::generateId(),
                $sourceType,self::id($sourceId),strtolower(self::required($body,'reward_key',64)),
                self::integer($body,'units',1,1_000_000,1),self::checked($body,'active')
            );
            $this->rewards->saveBinding($actor,$binding,$now,HttpAuditRequestId::fromRequest($request));
            return;
        }
        if($action==='retry'){
            $this->rewards->retry($actor,self::id(self::required($body,'grant_id',32)),$now,HttpAuditRequestId::fromRequest($request));
            return;
        }
        if($action==='retry_batch'){
            $this->rewards->retryDueForActor(
                $actor,self::integer($body,'limit',1,500,100),$now,HttpAuditRequestId::fromRequest($request)
            );
            return;
        }
        throw new InvalidArgumentException('Unknown reward action.');
    }

    private static function target(string $value):array
    {
        $parts=explode(':',$value,2);
        if(count($parts)!==2||!in_array($parts[0],['role','secondary_group'],true))throw new InvalidArgumentException('Reward target is invalid.');
        return $parts;
    }
    private static function source(string $value):array
    {
        $parts=explode(':',$value,2);
        if(count($parts)!==2||!in_array($parts[0],['giveaway','trophy'],true))throw new InvalidArgumentException('Reward source is invalid.');
        return $parts;
    }
    private static function id(mixed $value):EntityId
    {
        if(!is_string($value)||preg_match('/^[a-f0-9]{32}$/D',$value)!==1)throw new InvalidArgumentException('Entity id is invalid.');
        return EntityId::fromString($value);
    }
    /** @param array<string,mixed> $body */
    private static function required(array $body,string $key,int $max):string
    {
        $v=$body[$key]??null;
        if(!is_string($v)||trim($v)===''||strlen(trim($v))>$max)throw new InvalidArgumentException('Reward field is invalid: '.$key);
        return trim($v);
    }
    /** @param array<string,mixed> $body */
    private static function optional(array $body,string $key,int $max):?string
    {
        $v=$body[$key]??null;
        if($v===null||$v==='')return null;
        if(!is_string($v)||strlen($v)>$max)throw new InvalidArgumentException('Reward optional field is invalid: '.$key);
        $v=trim($v);return $v===''?null:$v;
    }
    /** @param array<string,mixed> $body */
    private static function integer(array $body,string $key,int $min,int $max,int $default):int
    {
        if(!array_key_exists($key,$body)||$body[$key]==='')return $default;
        $v=filter_var($body[$key],FILTER_VALIDATE_INT);
        if(!is_int($v)||$v<$min||$v>$max)throw new InvalidArgumentException('Reward integer field is invalid: '.$key);
        return $v;
    }
    /** @param array<string,mixed> $body */
    private static function checked(array $body,string $key):bool
    {
        return ($body[$key]??null)==='1'||($body[$key]??null)===1||($body[$key]??null)===true;
    }
}
