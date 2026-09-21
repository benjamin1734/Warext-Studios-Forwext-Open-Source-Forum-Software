<?php

declare(strict_types=1);

namespace Forwext\Core\Advertising;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Audit\AuditAction;
use Forwext\Core\Audit\AuditEvent;
use Forwext\Core\Audit\AuditRecorder;
use Forwext\Core\Audit\AuditRequestId;
use Forwext\Core\Audit\AuditScope;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Security\Secret\SecretKey;
use InvalidArgumentException;

final readonly class AdvertisingService
{
    public function __construct(
        private TransactionalQueryExecutor $database,
        private AdvertisingRepository $repository,
        private PermissionAuthorizer $authorizer,
        private AuditRecorder $audit,
        private SecretKey $frequencyKey,
    ){}

    /**
     * @param list<string> $routeTargets
     * @param list<EntityId> $forumTargets
     * @param list<EntityId> $groupTargets
     * @param list<AdvertisingDevice> $deviceTargets
     */
    public function saveCampaign(
        EntityId $actor,
        AdvertisingCampaign $campaign,
        array $routeTargets,
        array $forumTargets,
        array $groupTargets,
        array $deviceTargets,
        ?AuditRequestId $requestId=null,
    ):void{
        $this->require($actor,$campaign->kind->managementPermission());
        AdvertisingPlacementRegistry::assert($campaign->placementKey);
        $this->assertDestination($campaign->destinationUrl);

        $routes=[];
        foreach($routeTargets as $pattern){
            if(!is_string($pattern)||preg_match('/^[A-Za-z0-9*][A-Za-z0-9._*-]{0,127}$/D',$pattern)!==1){
                throw new InvalidArgumentException('Advertising route target is invalid.');
            }
            $routes[$pattern]=true;
        }
        foreach($forumTargets as $id)if(!$id instanceof EntityId)throw new InvalidArgumentException('Advertising forum target is invalid.');
        foreach($groupTargets as $id)if(!$id instanceof EntityId)throw new InvalidArgumentException('Advertising group target is invalid.');
        foreach($deviceTargets as $device)if(!$device instanceof AdvertisingDevice)throw new InvalidArgumentException('Advertising device target is invalid.');

        $before=$this->repository->campaign($campaign->campaignId);
        $event=new AuditEvent(
            AuditEvent::generateId(),AuditScope::Administration,$actor,
            AuditAction::fromString($before===null?'advertising.campaign.create':'advertising.campaign.update'),
            'advertising.campaign',$campaign->campaignId->value(),null,
            $before===null?'advertising.campaign.create':'advertising.campaign.update',
            $requestId??AuditRequestId::generate(),
            $before===null?[]:self::snapshot($before),
            self::snapshot($campaign)+[
                'route_targets'=>count($routes),'forum_targets'=>count($forumTargets),
                'group_targets'=>count($groupTargets),'device_targets'=>count($deviceTargets),
            ],
            new DateTimeImmutable('now',new DateTimeZone('UTC'))
        );

        $this->audit->mutate($event,function()use(
            $campaign,$actor,$routes,$forumTargets,$groupTargets,$deviceTargets
        ):void{
            $this->database->transaction(function()use(
                $campaign,$actor,$routes,$forumTargets,$groupTargets,$deviceTargets
            ):void{
                $this->repository->saveCampaign($campaign,$actor);
                $this->repository->replaceRouteTargets($campaign->campaignId,array_keys($routes));
                $this->repository->replaceForumTargets($campaign->campaignId,$forumTargets);
                $this->repository->replaceGroupTargets($campaign->campaignId,$groupTargets);
                $this->repository->replaceDeviceTargets($campaign->campaignId,$deviceTargets);
            });
        });
    }

    /**
     * @param list<string> $placements
     * @return array<string,AdvertisingCampaign>
     */
    public function select(AdvertisingRuntimeContext $context,array $placements):array
    {
        $selected=[];
        foreach($this->repository->activeCampaigns($placements,$context->now) as $campaign){
            if(isset($selected[$campaign->placementKey]))continue;
            if(!$this->matches($campaign,$context))continue;
            if($campaign->frequencyCap!==null&&$campaign->frequencyWindowSeconds!==null){
                $since=$context->now->modify('-'.$campaign->frequencyWindowSeconds.' seconds');
                if($this->repository->impressionCount(
                    $campaign->campaignId,$context->viewerHash,$since,$context->now
                ) >= $campaign->frequencyCap)continue;
            }
            $selected[$campaign->placementKey]=$campaign;
            $this->repository->recordEvent(
                $campaign->campaignId,AdvertisingEventType::Impression,$context->viewerHash,
                $context->routeName,$context->forumId,$context->device,$context->now
            );
        }
        return $selected;
    }

    public function trackClick(
        EntityId $campaignId,
        string $viewerHash,
        AdvertisingDevice $device,
        DateTimeImmutable $at,
    ):string{
        $campaign=$this->repository->campaign($campaignId)
            ??throw new InvalidArgumentException('Advertising campaign was not found.');
        if($campaign->destinationUrl===null)throw new InvalidArgumentException('Advertising campaign has no destination.');
        $this->assertDestination($campaign->destinationUrl);
        $this->repository->recordEvent(
            $campaignId,AdvertisingEventType::Click,$viewerHash,'advertising.click',null,$device,$at
        );
        return $campaign->destinationUrl;
    }

    /**
     * @return array{
     * campaigns:list<AdvertisingCampaign>,analytics:list<array{
     * campaign_id:EntityId,name:string,kind:AdvertisingKind,currency:string,impressions:int,clicks:int,revenue_minor:int
     * }>,placements:array<string,string>,groups:list<array{id:EntityId,name:string}>,forums:list<array{id:EntityId,title:string}>
     * }
     */
    public function managementSnapshot(EntityId $actor,DateTimeImmutable $from,DateTimeImmutable $to):array
    {
        if(!$this->allows($actor,'ads.manage')&&!$this->allows($actor,'notice.manage')){
            $this->require($actor,'ads.manage');
        }
        $campaigns=array_values(array_filter(
            $this->repository->campaigns(),
            fn(AdvertisingCampaign $campaign):bool=>$this->allows($actor,$campaign->kind->managementPermission())
        ));
        $allowedIds=[];
        foreach($campaigns as $campaign)$allowedIds[$campaign->campaignId->value()]=true;
        $analytics=array_values(array_filter(
            $this->repository->analytics($from,$to),
            static fn(array $row):bool=>isset($allowedIds[$row['campaign_id']->value()])
        ));
        return [
            'campaigns'=>$campaigns,
            'analytics'=>$analytics,
            'placements'=>AdvertisingPlacementRegistry::all(),
            'groups'=>$this->repository->groups(),
            'forums'=>$this->repository->forums(),
        ];
    }

    public function editableCampaign(EntityId $actor,EntityId $campaignId):?AdvertisingCampaign
    {
        $campaign=$this->repository->campaign($campaignId);
        if($campaign===null)return null;
        $this->require($actor,$campaign->kind->managementPermission());
        return $campaign;
    }

    /** @return list<string> */
    public function routeTargets(EntityId $campaignId):array{return $this->repository->routeTargets($campaignId);}
    /** @return list<EntityId> */
    public function forumTargets(EntityId $campaignId):array{return $this->repository->forumTargets($campaignId);}
    /** @return list<EntityId> */
    public function groupTargets(EntityId $campaignId):array{return $this->repository->groupTargets($campaignId);}
    /** @return list<AdvertisingDevice> */
    public function deviceTargets(EntityId $campaignId):array{return $this->repository->deviceTargets($campaignId);}

    public function viewerHash(?EntityId $viewer,?string $anonymousToken):string
    {
        $identity=$viewer===null?'anonymous:'.($anonymousToken??''):'user:'.$viewer->value();
        return hash_hmac('sha256',$identity,$this->frequencyKey->bytesForCrypto());
    }

    public function allows(EntityId $actor,string $permission):bool
    {
        return $this->authorizer->allows($actor,PermissionKey::fromString($permission));
    }

    private function matches(AdvertisingCampaign $campaign,AdvertisingRuntimeContext $context):bool
    {
        $routes=$this->repository->routeTargets($campaign->campaignId);
        if($routes!==[]){
            $matched=false;
            foreach($routes as $pattern){
                if(self::routeMatches($pattern,$context->routeName)){$matched=true;break;}
            }
            if(!$matched)return false;
        }

        $forums=$this->repository->forumTargets($campaign->campaignId);
        if($forums!==[]){
            if($context->forumId===null)return false;
            $matched=false;
            foreach($forums as $forum)if($forum->equals($context->forumId)){$matched=true;break;}
            if(!$matched)return false;
        }

        $groups=$this->repository->groupTargets($campaign->campaignId);
        if($groups!==[]){
            $wanted=[];foreach($groups as $group)$wanted[$group->value()]=true;
            $matched=false;
            foreach($context->groupIds as $group)if(isset($wanted[$group->value()])){$matched=true;break;}
            if(!$matched)return false;
        }

        $devices=$this->repository->deviceTargets($campaign->campaignId);
        if($devices!==[]){
            $matched=false;
            foreach($devices as $device)if($device===$context->device){$matched=true;break;}
            if(!$matched)return false;
        }
        return true;
    }

    private static function routeMatches(string $pattern,string $routeName):bool
    {
        if($pattern==='*')return true;
        $regex='/^'.str_replace('\\*','.*',preg_quote($pattern,'/')).'$/D';
        return preg_match($regex,$routeName)===1;
    }

    private function assertDestination(?string $url):void
    {
        if($url===null)return;
        if(str_starts_with($url,'/')&&!str_starts_with($url,'//')&&!preg_match('/[\x00-\x20\x7F]/',$url))return;
        if(filter_var($url,FILTER_VALIDATE_URL)===false){
            throw new InvalidArgumentException('Advertising destination must be a relative path or HTTPS URL.');
        }
        $parts=parse_url($url);
        if(!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'||!isset($parts['host'])
            ||isset($parts['user'])||isset($parts['pass'])
        ){
            throw new InvalidArgumentException('Advertising destination must be HTTPS without embedded credentials.');
        }
    }

    private function require(EntityId $actor,string $permission):void
    {
        $decision=$this->authorizer->resolve($actor,PermissionKey::fromString($permission));
        if(!$decision->isAllowed())throw new PermissionDeniedException($decision);
    }

    /** @return array<string,scalar|null> */
    private static function snapshot(AdvertisingCampaign $campaign):array
    {
        return [
            'key'=>$campaign->key,'kind'=>$campaign->kind->value,'placement'=>$campaign->placementKey,
            'enabled'=>$campaign->enabled,'priority'=>$campaign->priority,'frequency_cap'=>$campaign->frequencyCap,
            'frequency_window_seconds'=>$campaign->frequencyWindowSeconds,
            'impression_value_minor'=>$campaign->impressionValueMinor,'click_value_minor'=>$campaign->clickValueMinor,
            'currency'=>$campaign->currency,'starts_at'=>$campaign->startsAt?->format(DATE_ATOM),
            'ends_at'=>$campaign->endsAt?->format(DATE_ATOM),
        ];
    }
}
