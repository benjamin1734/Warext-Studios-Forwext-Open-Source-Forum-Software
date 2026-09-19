<?php

declare(strict_types=1);

namespace Forwext\App\Web\Promotion;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Audit\HttpAuditRequestId;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Domain\User\Username;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Promotion\PromotionDefinition;
use Forwext\Core\Promotion\PromotionRuleType;
use Forwext\Core\Promotion\PromotionService;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;
use ValueError;

final readonly class PromotionManageHandler implements RequestHandlerInterface
{
    public function __construct(
        private PromotionService $promotions,
        private UserRepository $users,
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
                return Response::text('',303)->withHeader('Location',$this->basePath->prepend('/admin/promotions?updated=1'))->withHeader('Cache-Control','no-store');
            }
            $token=$request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
            if(!is_string($token)||$token==='')return Response::text('Internal Server Error',500)->withHeader('Cache-Control','no-store');
            $definitions=$this->promotions->definitions($actor);
            $selected=null;
            $raw=$request->query()['id']??null;
            if(is_string($raw)&&$raw!==''){
                $selected=$this->promotions->definition($actor,self::id($raw));
                if($selected===null)return Response::text('Not Found',404)->withHeader('Cache-Control','no-store');
            }
            return Response::html(PromotionHtml::manage(
                $definitions,$selected,$this->promotions->rewardOptions($actor),$this->basePath,$token,
                ($request->query()['updated']??null)==='1'
            ))->withHeader('Cache-Control','private, no-store');
        }catch(PermissionDeniedException){
            return Response::text('Forbidden',403)->withHeader('Cache-Control','no-store');
        }catch(InvalidArgumentException|ValueError){
            return Response::text('Bad Request',400)->withHeader('Cache-Control','no-store');
        }
    }

    private function mutate(EntityId $actor,Request $request):void
    {
        $body=$request->parsedBody();
        $action=$body['action']??null;
        if(!is_string($action))throw new InvalidArgumentException('Promotion action is missing.');
        $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
        if($action==='save'){
            $raw=self::optional($body,'promotion_id',32);
            $existing=$raw===null?null:$this->promotions->definition($actor,self::id($raw));
            if($raw!==null&&$existing===null)throw new InvalidArgumentException('Promotion definition does not exist.');
            $definition=new PromotionDefinition(
                $existing?->promotionId??PromotionDefinition::generateId(),
                strtolower(self::required($body,'key',64)),self::required($body,'name',120),
                self::checked($body,'active'),self::integer($body,'priority',0,65535,100),
                PromotionRuleType::from(self::required($body,'rule_type',40)),
                self::integer($body,'threshold',1,1_000_000_000,1),
                strtolower(self::required($body,'reward_key',64)),
                self::integer($body,'units',1,1_000_000,1),
                self::checked($body,'revoke_when_unqualified'),
                $existing?->createdAt??$now,$now
            );
            $this->promotions->save($actor,$definition,$now,HttpAuditRequestId::fromRequest($request));
            return;
        }
        if($action==='evaluate_user'){
            $user=$this->users->findByUsername(Username::fromString(self::required($body,'username',64)));
            if($user===null)throw new InvalidArgumentException('Promotion target user was not found.');
            $this->promotions->evaluateUserForActor($actor,$user->id(),$now,HttpAuditRequestId::fromRequest($request));
            return;
        }
        if($action==='evaluate_batch'){
            $this->promotions->evaluateBatchForActor(
                $actor,self::integer($body,'limit',1,500,100),$now,HttpAuditRequestId::fromRequest($request)
            );
            return;
        }
        throw new InvalidArgumentException('Unknown promotion action.');
    }

    private static function id(mixed $value):EntityId
    {
        if(!is_string($value)||preg_match('/^[a-f0-9]{32}$/D',$value)!==1)throw new InvalidArgumentException('Promotion id is invalid.');
        return EntityId::fromString($value);
    }
    /** @param array<string,mixed> $body */
    private static function required(array $body,string $key,int $max):string
    {
        $v=$body[$key]??null;if(!is_string($v)||trim($v)===''||strlen(trim($v))>$max)throw new InvalidArgumentException('Promotion field is invalid: '.$key);
        return trim($v);
    }
    /** @param array<string,mixed> $body */
    private static function optional(array $body,string $key,int $max):?string
    {
        $v=$body[$key]??null;if($v===null||$v==='')return null;
        if(!is_string($v)||strlen($v)>$max)throw new InvalidArgumentException('Promotion optional field is invalid: '.$key);
        $v=trim($v);return $v===''?null:$v;
    }
    /** @param array<string,mixed> $body */
    private static function integer(array $body,string $key,int $min,int $max,int $default):int
    {
        if(!array_key_exists($key,$body)||$body[$key]==='')return $default;
        $v=filter_var($body[$key],FILTER_VALIDATE_INT);
        if(!is_int($v)||$v<$min||$v>$max)throw new InvalidArgumentException('Promotion integer field is invalid: '.$key);
        return $v;
    }
    /** @param array<string,mixed> $body */
    private static function checked(array $body,string $key):bool
    {
        return ($body[$key]??null)==='1'||($body[$key]??null)===1||($body[$key]??null)===true;
    }
}
