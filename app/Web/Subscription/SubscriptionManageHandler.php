<?php

declare(strict_types=1);

namespace Forwext\App\Web\Subscription;

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
use Forwext\Core\Subscription\SubscriptionPlan;
use Forwext\Core\Subscription\SubscriptionRepository;
use Forwext\Core\Subscription\SubscriptionService;
use InvalidArgumentException;

final readonly class SubscriptionManageHandler implements RequestHandlerInterface
{
    public function __construct(
        private SubscriptionService $subscriptions,
        private SubscriptionRepository $repository,
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
                return Response::redirect(
                    $this->basePath->prepend('/admin/subscriptions?updated=1'),303
                )->withHeader('Cache-Control','no-store');
            }

            $csrf=$request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
            if(!is_string($csrf)||$csrf===''){
                return Response::text('Internal Server Error',500)->withHeader('Cache-Control','no-store');
            }

            $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
            $snapshot=$this->subscriptions->managementSnapshot($actor,$now);
            $selected=null;$selectedRoles=[];$selectedPermissions=[];
            $raw=$request->query()['id']??null;
            if(is_string($raw)&&$raw!==''){
                $selected=$this->repository->plan(self::id($raw));
                if($selected===null)return Response::text('Not Found',404)->withHeader('Cache-Control','no-store');
                $selectedRoles=$this->repository->roleIds($selected->planId);
                $selectedPermissions=$this->repository->permissionKeys($selected->planId);
            }

            $usernames=[];
            foreach($snapshot['subscriptions'] as $subscription){
                $user=$this->users->find($subscription->userId);
                if($user!==null)$usernames[$subscription->userId->value()]=$user->username()->display();
            }

            return Response::html(SubscriptionHtml::manage(
                $snapshot['plans'],$snapshot['subscriptions'],$snapshot['roles'],$snapshot['permissions'],
                $selected,$selectedRoles,$selectedPermissions,$usernames,$this->basePath,$csrf,
                ($request->query()['updated']??null)==='1'
            ))->withHeader('Cache-Control','private, no-store')
                ->withHeader('X-Robots-Tag','noindex,nofollow');
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
        if(!is_string($action))throw new InvalidArgumentException('Subscription management action is missing.');
        $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));

        if($action==='save_plan'){
            $raw=self::optional($body,'plan_id',32);
            $existing=$raw===null?null:$this->repository->plan(self::id($raw));
            if($raw!==null&&$existing===null)throw new InvalidArgumentException('Subscription plan was not found.');

            $plan=new SubscriptionPlan(
                $existing?->planId??SubscriptionPlan::generateId(),
                strtolower(self::required($body,'key',64)),
                self::required($body,'name',120),
                self::optional($body,'description',20000)??'',
                self::checked($body,'active'),
                self::money(self::required($body,'price',32)),
                strtoupper(self::required($body,'currency',3)),
                self::optionalInteger($body,'duration_days',1,3650),
                self::integer($body,'sort_order',0,65535,100),
                $existing?->createdAt??$now,
                $now
            );
            $this->subscriptions->savePlan(
                $actor,$plan,self::ids($body['role_ids']??[]),self::strings($body['permission_keys']??[],96),
                $now,HttpAuditRequestId::fromRequest($request)
            );
            return;
        }

        if($action==='grant'){
            $user=$this->users->findByUsername(Username::fromString(self::required($body,'username',64)));
            if($user===null)throw new InvalidArgumentException('Subscription target user was not found.');
            $this->subscriptions->manualGrant(
                $actor,$user->id(),self::id(self::required($body,'plan_id',32)),
                $now,HttpAuditRequestId::fromRequest($request)
            );
            return;
        }

        if($action==='revoke'){
            $this->subscriptions->revoke(
                $actor,self::id(self::required($body,'subscription_id',32)),
                $now,HttpAuditRequestId::fromRequest($request)
            );
            return;
        }

        if($action==='expire_due'){
            $this->subscriptions->expireDue(200,$now);
            return;
        }

        throw new InvalidArgumentException('Unknown subscription management action.');
    }

    private static function id(mixed $value):EntityId
    {
        if(!is_string($value)||preg_match('/^[a-f0-9]{32}$/D',$value)!==1){
            throw new InvalidArgumentException('Subscription id is invalid.');
        }
        return EntityId::fromString($value);
    }

    /** @param array<string,mixed> $body */
    private static function required(array $body,string $key,int $max):string
    {
        $value=$body[$key]??null;
        if(!is_string($value)||trim($value)===''||strlen(trim($value))>$max){
            throw new InvalidArgumentException('Subscription field is invalid: '.$key);
        }
        return trim($value);
    }

    /** @param array<string,mixed> $body */
    private static function optional(array $body,string $key,int $max):?string
    {
        $value=$body[$key]??null;
        if($value===null||$value==='')return null;
        if(!is_string($value)||strlen($value)>$max){
            throw new InvalidArgumentException('Subscription optional field is invalid: '.$key);
        }
        $value=trim($value);
        return $value===''?null:$value;
    }

    /** @param array<string,mixed> $body */
    private static function integer(array $body,string $key,int $min,int $max,int $default):int
    {
        if(!array_key_exists($key,$body)||$body[$key]==='')return $default;
        $value=filter_var($body[$key],FILTER_VALIDATE_INT);
        if(!is_int($value)||$value<$min||$value>$max){
            throw new InvalidArgumentException('Subscription integer field is invalid: '.$key);
        }
        return $value;
    }

    /** @param array<string,mixed> $body */
    private static function optionalInteger(array $body,string $key,int $min,int $max):?int
    {
        if(!array_key_exists($key,$body)||$body[$key]===''||$body[$key]===null)return null;
        $value=filter_var($body[$key],FILTER_VALIDATE_INT);
        if(!is_int($value)||$value<$min||$value>$max){
            throw new InvalidArgumentException('Subscription optional integer field is invalid: '.$key);
        }
        return $value;
    }

    /** @param array<string,mixed> $body */
    private static function checked(array $body,string $key):bool
    {
        return ($body[$key]??null)==='1'||($body[$key]??null)===1||($body[$key]??null)===true;
    }

    private static function money(string $value):int
    {
        if(preg_match('/^(\d{1,13})(?:\.(\d{1,2}))?$/D',$value,$match)!==1){
            throw new InvalidArgumentException('Subscription price is invalid.');
        }
        $major=(int)$match[1];
        if($major>intdiv(PHP_INT_MAX,100))throw new InvalidArgumentException('Subscription price is too large.');
        return $major*100+(int)str_pad($match[2]??'',2,'0');
    }

    /** @return list<EntityId> */
    private static function ids(mixed $values):array
    {
        if($values===null||$values==='')return [];
        if(!is_array($values)||count($values)>100)throw new InvalidArgumentException('Subscription role list is invalid.');
        $result=[];
        foreach($values as $value){
            $id=self::id($value);
            $result[$id->value()]=$id;
        }
        return array_values($result);
    }

    /** @return list<string> */
    private static function strings(mixed $values,int $max):array
    {
        if($values===null||$values==='')return [];
        if(!is_array($values)||count($values)>200)throw new InvalidArgumentException('Subscription permission list is invalid.');
        $result=[];
        foreach($values as $value){
            if(!is_string($value)||trim($value)===''||strlen(trim($value))>$max){
                throw new InvalidArgumentException('Subscription permission entry is invalid.');
            }
            $result[trim($value)]=true;
        }
        return array_keys($result);
    }
}
