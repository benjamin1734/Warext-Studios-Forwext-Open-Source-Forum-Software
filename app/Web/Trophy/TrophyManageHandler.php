<?php

declare(strict_types=1);

namespace Forwext\App\Web\Trophy;

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
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Trophy\TrophyDefinition;
use Forwext\Core\Trophy\TrophyKind;
use Forwext\Core\Trophy\TrophyRuleType;
use Forwext\Core\Trophy\TrophyService;
use InvalidArgumentException;
use ValueError;

final readonly class TrophyManageHandler implements RequestHandlerInterface
{
    public function __construct(
        private TrophyService $trophies,
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
                $selected=$this->mutate($actor,$request);
                $location='/admin/trophies?updated=1'.($selected===null?'':'&id='.rawurlencode($selected->value()));
                return Response::text('',303)->withHeader('Location',$this->basePath->prepend($location))->withHeader('Cache-Control','no-store');
            }
            $token=$request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
            if(!is_string($token)||$token==='')return Response::text('Internal Server Error',500)->withHeader('Cache-Control','no-store');
            $snapshot=$this->trophies->managementSnapshot($actor);
            $selected=null;
            $raw=$request->query()['id']??null;
            if(is_string($raw)&&$raw!==''){
                $selected=$this->trophies->definition($actor,self::id($raw));
                if($selected===null)return Response::text('Not Found',404)->withHeader('Cache-Control','no-store');
            }
            return Response::html(TrophyHtml::manage(
                $snapshot['definitions'],$selected,$snapshot['can_manage'],$snapshot['can_award'],
                $this->basePath,$token,($request->query()['updated']??null)==='1'
            ))->withHeader('Cache-Control','private, no-store');
        }catch(PermissionDeniedException){
            return Response::text('Forbidden',403)->withHeader('Cache-Control','no-store');
        }catch(InvalidArgumentException|ValueError){
            return Response::text('Bad Request',400)->withHeader('Cache-Control','no-store');
        }
    }

    private function mutate(EntityId $actor,Request $request):?EntityId
    {
        $body=$request->parsedBody();
        $action=$body['action']??null;
        if(!is_string($action))throw new InvalidArgumentException('Trophy action is missing.');
        $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));

        if($action==='save'){
            $rawId=self::optional($body,'trophy_id',32);
            $existing=$rawId===null?null:$this->trophies->definition($actor,self::id($rawId));
            if($rawId!==null&&$existing===null)throw new InvalidArgumentException('Trophy definition does not exist.');
            $rule=TrophyRuleType::from(self::required($body,'rule_type',40));
            $threshold=$rule===TrophyRuleType::Manual?null:self::integer($body,'threshold',1,1_000_000_000,null);
            $definition=new TrophyDefinition(
                $existing?->trophyId??TrophyDefinition::generateId(),
                strtolower(self::required($body,'key',64)),self::required($body,'name',120),
                self::optional($body,'description',2000)??'',TrophyKind::from(self::required($body,'kind',16)),
                self::checked($body,'active'),self::integer($body,'priority',0,65535,100),
                self::optional($body,'icon_path',512),self::optional($body,'banner_path',512),
                $rule,$threshold,$existing?->createdAt??$now,$now
            );
            $this->trophies->saveDefinition($actor,$definition,$now,HttpAuditRequestId::fromRequest($request));
            return $definition->trophyId;
        }

        $user=$this->users->findByUsername(Username::fromString(self::required($body,'username',64)));
        if($user===null)throw new InvalidArgumentException('Target user was not found.');

        if($action==='evaluate'){
            $this->trophies->evaluateUserForActor($actor,$user->id(),$now,HttpAuditRequestId::fromRequest($request));
            return null;
        }

        $trophyId=self::id(self::required($body,'trophy_id',32));
        if($action==='award'){
            $this->trophies->awardManual(
                $actor,$trophyId,$user->id(),self::optional($body,'reason',500)??'',
                $now,HttpAuditRequestId::fromRequest($request)
            );
            return $trophyId;
        }
        if($action==='revoke'){
            $this->trophies->revoke(
                $actor,$trophyId,$user->id(),self::required($body,'reason',500),
                $now,HttpAuditRequestId::fromRequest($request)
            );
            return $trophyId;
        }
        throw new InvalidArgumentException('Unknown trophy action.');
    }

    private static function id(mixed $value):EntityId
    {
        if(!is_string($value)||preg_match('/^[a-f0-9]{32}$/D',$value)!==1)throw new InvalidArgumentException('Trophy id is invalid.');
        return EntityId::fromString($value);
    }
    /** @param array<string,mixed> $body */
    private static function required(array $body,string $key,int $max):string
    {
        $v=$body[$key]??null;
        if(!is_string($v)||trim($v)===''||strlen(trim($v))>$max)throw new InvalidArgumentException('Trophy field is invalid: '.$key);
        return trim($v);
    }
    /** @param array<string,mixed> $body */
    private static function optional(array $body,string $key,int $max):?string
    {
        $v=$body[$key]??null;
        if($v===null||$v==='')return null;
        if(!is_string($v)||strlen($v)>$max)throw new InvalidArgumentException('Trophy optional field is invalid: '.$key);
        $v=trim($v);return $v===''?null:$v;
    }
    /** @param array<string,mixed> $body */
    private static function integer(array $body,string $key,int $min,int $max,?int $default):?int
    {
        if(!array_key_exists($key,$body)||$body[$key]==='')return $default;
        $v=filter_var($body[$key],FILTER_VALIDATE_INT);
        if(!is_int($v)||$v<$min||$v>$max)throw new InvalidArgumentException('Trophy integer field is invalid: '.$key);
        return $v;
    }
    /** @param array<string,mixed> $body */
    private static function checked(array $body,string $key):bool
    {
        return ($body[$key]??null)==='1'||($body[$key]??null)===1||($body[$key]??null)===true;
    }
}
