<?php

declare(strict_types=1);

namespace Forwext\Core\Advertising;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class DatabaseAdvertisingRepository implements AdvertisingRepository
{
    public function __construct(private TransactionalQueryExecutor $database){}

    public function campaigns(int $limit=300):array
    {
        if($limit<1||$limit>1000)throw new InvalidArgumentException('Advertising campaign limit is invalid.');
        $rows=$this->database->fetchAll(new CompiledQuery(
            'SELECT * FROM forwext_ad_campaigns ORDER BY updated_at_utc DESC,campaign_id DESC LIMIT '.$limit
        ));
        return array_map($this->hydrateCampaign(...),$rows);
    }

    public function campaign(EntityId $campaignId,bool $forUpdate=false):?AdvertisingCampaign
    {
        $row=$this->database->fetchOne(new CompiledQuery(
            'SELECT * FROM forwext_ad_campaigns WHERE campaign_id=:id LIMIT 1'.($forUpdate?' FOR UPDATE':''),
            ['id'=>$campaignId->value()]
        ));
        return $row===null?null:$this->hydrateCampaign($row);
    }

    public function saveCampaign(AdvertisingCampaign $campaign,EntityId $actor):void
    {
        UserId::assert($actor);
        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_ad_campaigns '
            . '(campaign_id,campaign_key,kind,name,headline,body,destination_url,placement_key,enabled,priority,'
            . 'frequency_cap,frequency_window_seconds,impression_value_minor,click_value_minor,currency,'
            . 'starts_at_utc,ends_at_utc,created_by_user_id,updated_by_user_id,created_at_utc,updated_at_utc) '
            . 'VALUES (:id,:key,:kind,:name,:headline,:body,:destination,:placement,:enabled,:priority,:cap,:window,'
            . ':impression_value,:click_value,:currency,:starts,:ends,:actor,:actor,:created,:updated) '
            . 'ON DUPLICATE KEY UPDATE campaign_key=VALUES(campaign_key),kind=VALUES(kind),name=VALUES(name),'
            . 'headline=VALUES(headline),body=VALUES(body),destination_url=VALUES(destination_url),'
            . 'placement_key=VALUES(placement_key),enabled=VALUES(enabled),priority=VALUES(priority),'
            . 'frequency_cap=VALUES(frequency_cap),frequency_window_seconds=VALUES(frequency_window_seconds),'
            . 'impression_value_minor=VALUES(impression_value_minor),click_value_minor=VALUES(click_value_minor),'
            . 'currency=VALUES(currency),starts_at_utc=VALUES(starts_at_utc),ends_at_utc=VALUES(ends_at_utc),'
            . 'updated_by_user_id=VALUES(updated_by_user_id),updated_at_utc=VALUES(updated_at_utc)',
            [
                'id'=>$campaign->campaignId->value(),'key'=>$campaign->key,'kind'=>$campaign->kind->value,
                'name'=>$campaign->name,'headline'=>$campaign->headline,'body'=>$campaign->body,
                'destination'=>$campaign->destinationUrl,'placement'=>$campaign->placementKey,
                'enabled'=>$campaign->enabled,'priority'=>$campaign->priority,'cap'=>$campaign->frequencyCap,
                'window'=>$campaign->frequencyWindowSeconds,'impression_value'=>$campaign->impressionValueMinor,
                'click_value'=>$campaign->clickValueMinor,'currency'=>$campaign->currency,
                'starts'=>$campaign->startsAt===null?null:self::format($campaign->startsAt),
                'ends'=>$campaign->endsAt===null?null:self::format($campaign->endsAt),
                'actor'=>$actor->value(),'created'=>self::format($campaign->createdAt),
                'updated'=>self::format($campaign->updatedAt),
            ]
        ));
    }

    public function groups():array
    {
        $rows=$this->database->fetchAll(new CompiledQuery(
            'SELECT group_id,name FROM forwext_user_groups ORDER BY sort_order,name,group_id'
        ));
        return array_map(static fn(array $row):array=>[
            'id'=>EntityId::fromString((string)$row['group_id']),
            'name'=>(string)$row['name'],
        ],$rows);
    }

    public function forums():array
    {
        $rows=$this->database->fetchAll(new CompiledQuery(
            "SELECT node_id,title FROM forwext_nodes WHERE node_type='forum' ORDER BY sort_order,title,node_id"
        ));
        return array_map(static fn(array $row):array=>[
            'id'=>EntityId::fromString((string)$row['node_id']),
            'title'=>(string)$row['title'],
        ],$rows);
    }

    public function routeTargets(EntityId $campaignId):array
    {
        return $this->stringTargets('forwext_ad_route_targets','route_pattern',$campaignId);
    }

    public function forumTargets(EntityId $campaignId):array
    {
        return array_map(
            static fn(string $value):EntityId=>EntityId::fromString($value),
            $this->stringTargets('forwext_ad_forum_targets','forum_id',$campaignId)
        );
    }

    public function groupTargets(EntityId $campaignId):array
    {
        return array_map(
            static fn(string $value):EntityId=>EntityId::fromString($value),
            $this->stringTargets('forwext_ad_group_targets','group_id',$campaignId)
        );
    }

    public function deviceTargets(EntityId $campaignId):array
    {
        return array_map(
            static fn(string $value):AdvertisingDevice=>AdvertisingDevice::from($value),
            $this->stringTargets('forwext_ad_device_targets','device',$campaignId)
        );
    }

    public function replaceRouteTargets(EntityId $campaignId,array $patterns):void
    {
        $this->replaceStrings('forwext_ad_route_targets','route_pattern',$campaignId,$patterns);
    }

    public function replaceForumTargets(EntityId $campaignId,array $forumIds):void
    {
        $this->replaceStrings(
            'forwext_ad_forum_targets','forum_id',$campaignId,
            array_map(static fn(EntityId $id):string=>$id->value(),$forumIds)
        );
    }

    public function replaceGroupTargets(EntityId $campaignId,array $groupIds):void
    {
        $this->replaceStrings(
            'forwext_ad_group_targets','group_id',$campaignId,
            array_map(static fn(EntityId $id):string=>$id->value(),$groupIds)
        );
    }

    public function replaceDeviceTargets(EntityId $campaignId,array $devices):void
    {
        $this->replaceStrings(
            'forwext_ad_device_targets','device',$campaignId,
            array_map(static fn(AdvertisingDevice $device):string=>$device->value,$devices)
        );
    }

    public function activeCampaigns(array $placementKeys,DateTimeImmutable $at,int $limit=200):array
    {
        if($placementKeys===[]||$limit<1||$limit>500)return [];
        $parameters=['at'=>self::format($at)];
        $holders=[];
        foreach(array_values(array_unique($placementKeys)) as $index=>$placement){
            AdvertisingPlacementRegistry::assert($placement);
            $key='placement_'.$index;
            $holders[]=':'.$key;
            $parameters[$key]=$placement;
        }
        $rows=$this->database->fetchAll(new CompiledQuery(
            'SELECT * FROM forwext_ad_campaigns WHERE enabled=1 '
            . 'AND placement_key IN ('.implode(',',$holders).') '
            . 'AND (starts_at_utc IS NULL OR starts_at_utc<=:at) '
            . 'AND (ends_at_utc IS NULL OR ends_at_utc>:at) '
            . 'ORDER BY priority DESC,updated_at_utc DESC,campaign_id ASC LIMIT '.$limit,
            $parameters
        ));
        return array_map($this->hydrateCampaign(...),$rows);
    }

    public function impressionCount(
        EntityId $campaignId,string $viewerHash,DateTimeImmutable $since,DateTimeImmutable $until
    ):int{
        if(preg_match('/^[a-f0-9]{64}$/D',$viewerHash)!==1){
            throw new InvalidArgumentException('Advertising viewer hash is invalid.');
        }
        return (int)$this->database->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_ad_events WHERE campaign_id=:campaign AND event_type='impression' "
            . 'AND viewer_hash=:viewer AND occurred_at_utc>=:since AND occurred_at_utc<:until',
            [
                'campaign'=>$campaignId->value(),'viewer'=>$viewerHash,
                'since'=>self::format($since),'until'=>self::format($until),
            ]
        ));
    }

    public function recordEvent(
        EntityId $campaignId,AdvertisingEventType $type,string $viewerHash,string $routeName,
        ?EntityId $forumId,AdvertisingDevice $device,DateTimeImmutable $at
    ):void{
        if(preg_match('/^[a-f0-9]{64}$/D',$viewerHash)!==1||$routeName===''||strlen($routeName)>128){
            throw new InvalidArgumentException('Advertising event context is invalid.');
        }
        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_ad_events '
            . '(event_id,campaign_id,event_type,viewer_hash,route_name,forum_id,device,occurred_at_utc) '
            . 'VALUES (:event,:campaign,:type,:viewer,:route,:forum,:device,:occurred)',
            [
                'event'=>bin2hex(random_bytes(16)),'campaign'=>$campaignId->value(),'type'=>$type->value,
                'viewer'=>$viewerHash,'route'=>$routeName,'forum'=>$forumId?->value(),'device'=>$device->value,
                'occurred'=>self::format($at),
            ]
        ));
    }

    public function analytics(DateTimeImmutable $from,DateTimeImmutable $to):array
    {
        $rows=$this->database->fetchAll(new CompiledQuery(
            'SELECT c.campaign_id,c.name,c.kind,c.currency,'
            . "SUM(CASE WHEN e.event_type='impression' THEN 1 ELSE 0 END) AS impressions,"
            . "SUM(CASE WHEN e.event_type='click' THEN 1 ELSE 0 END) AS clicks,"
            . "SUM(CASE WHEN e.event_type='impression' THEN c.impression_value_minor "
            . "WHEN e.event_type='click' THEN c.click_value_minor ELSE 0 END) AS revenue_minor "
            . 'FROM forwext_ad_campaigns c LEFT JOIN forwext_ad_events e '
            . 'ON e.campaign_id=c.campaign_id AND e.occurred_at_utc>=:from AND e.occurred_at_utc<:to '
            . 'GROUP BY c.campaign_id,c.name,c.kind,c.currency,c.priority '
            . 'ORDER BY revenue_minor DESC,impressions DESC,c.priority DESC,c.campaign_id',
            ['from'=>self::format($from),'to'=>self::format($to)]
        ));
        return array_map(static fn(array $row):array=>[
            'campaign_id'=>EntityId::fromString((string)$row['campaign_id']),
            'name'=>(string)$row['name'],
            'kind'=>AdvertisingKind::from((string)$row['kind']),
            'currency'=>(string)$row['currency'],
            'impressions'=>(int)$row['impressions'],
            'clicks'=>(int)$row['clicks'],
            'revenue_minor'=>(int)$row['revenue_minor'],
        ],$rows);
    }

    /** @return list<string> */
    private function stringTargets(string $table,string $column,EntityId $campaignId):array
    {
        $allowed=[
            'forwext_ad_route_targets'=>'route_pattern',
            'forwext_ad_forum_targets'=>'forum_id',
            'forwext_ad_group_targets'=>'group_id',
            'forwext_ad_device_targets'=>'device',
        ];
        if(($allowed[$table]??null)!==$column)throw new InvalidArgumentException('Advertising target table is invalid.');
        $rows=$this->database->fetchAll(new CompiledQuery(
            'SELECT '.$column.' FROM '.$table.' WHERE campaign_id=:campaign ORDER BY '.$column,
            ['campaign'=>$campaignId->value()]
        ));
        return array_map(static fn(array $row):string=>(string)$row[$column],$rows);
    }

    /** @param list<string> $values */
    private function replaceStrings(string $table,string $column,EntityId $campaignId,array $values):void
    {
        $allowed=[
            'forwext_ad_route_targets'=>'route_pattern',
            'forwext_ad_forum_targets'=>'forum_id',
            'forwext_ad_group_targets'=>'group_id',
            'forwext_ad_device_targets'=>'device',
        ];
        if(($allowed[$table]??null)!==$column)throw new InvalidArgumentException('Advertising target table is invalid.');
        $this->database->execute(new CompiledQuery(
            'DELETE FROM '.$table.' WHERE campaign_id=:campaign',['campaign'=>$campaignId->value()]
        ));
        foreach(array_values(array_unique($values)) as $value){
            $this->database->execute(new CompiledQuery(
                'INSERT INTO '.$table.'(campaign_id,'.$column.') VALUES (:campaign,:value)',
                ['campaign'=>$campaignId->value(),'value'=>$value]
            ));
        }
    }

    /** @param array<string,mixed> $row */
    private function hydrateCampaign(array $row):AdvertisingCampaign
    {
        return new AdvertisingCampaign(
            EntityId::fromString((string)$row['campaign_id']),(string)$row['campaign_key'],
            AdvertisingKind::from((string)$row['kind']),(string)$row['name'],(string)$row['headline'],
            (string)$row['body'],$row['destination_url']===null?null:(string)$row['destination_url'],
            (string)$row['placement_key'],(bool)$row['enabled'],(int)$row['priority'],
            $row['frequency_cap']===null?null:(int)$row['frequency_cap'],
            $row['frequency_window_seconds']===null?null:(int)$row['frequency_window_seconds'],
            (int)$row['impression_value_minor'],(int)$row['click_value_minor'],(string)$row['currency'],
            $row['starts_at_utc']===null?null:self::parse((string)$row['starts_at_utc']),
            $row['ends_at_utc']===null?null:self::parse((string)$row['ends_at_utc']),
            self::parse((string)$row['created_at_utc']),self::parse((string)$row['updated_at_utc'])
        );
    }

    private static function format(DateTimeImmutable $value):string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function parse(string $value):DateTimeImmutable
    {
        $parsed=DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u',$value,new DateTimeZone('UTC'));
        if(!$parsed instanceof DateTimeImmutable){
            $parsed=new DateTimeImmutable($value,new DateTimeZone('UTC'));
        }
        return $parsed;
    }
}
