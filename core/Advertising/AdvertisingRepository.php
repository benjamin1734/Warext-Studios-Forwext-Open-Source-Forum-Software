<?php

declare(strict_types=1);

namespace Forwext\Core\Advertising;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

interface AdvertisingRepository
{
    /** @return list<AdvertisingCampaign> */
    public function campaigns(int $limit=300):array;
    public function campaign(EntityId $campaignId,bool $forUpdate=false):?AdvertisingCampaign;
    public function saveCampaign(AdvertisingCampaign $campaign,EntityId $actor):void;

    /** @return list<string> */
    public function routeTargets(EntityId $campaignId):array;
    /** @return list<EntityId> */
    public function forumTargets(EntityId $campaignId):array;
    /** @return list<EntityId> */
    public function groupTargets(EntityId $campaignId):array;
    /** @return list<AdvertisingDevice> */
    public function deviceTargets(EntityId $campaignId):array;

    /** @param list<string> $patterns */
    public function replaceRouteTargets(EntityId $campaignId,array $patterns):void;
    /** @param list<EntityId> $forumIds */
    public function replaceForumTargets(EntityId $campaignId,array $forumIds):void;
    /** @param list<EntityId> $groupIds */
    public function replaceGroupTargets(EntityId $campaignId,array $groupIds):void;
    /** @param list<AdvertisingDevice> $devices */
    public function replaceDeviceTargets(EntityId $campaignId,array $devices):void;

    /** @param list<string> $placementKeys @return list<AdvertisingCampaign> */
    public function activeCampaigns(array $placementKeys,DateTimeImmutable $at,int $limit=200):array;

    public function impressionCount(
        EntityId $campaignId,
        string $viewerHash,
        DateTimeImmutable $since,
        DateTimeImmutable $until,
    ):int;

    public function recordEvent(
        EntityId $campaignId,
        AdvertisingEventType $type,
        string $viewerHash,
        string $routeName,
        ?EntityId $forumId,
        AdvertisingDevice $device,
        DateTimeImmutable $at,
    ):void;

    /**
     * @return list<array{
     *   campaign_id:EntityId,name:string,kind:AdvertisingKind,currency:string,
     *   impressions:int,clicks:int,revenue_minor:int
     * }>
     */
    public function analytics(DateTimeImmutable $from,DateTimeImmutable $to):array;
}
