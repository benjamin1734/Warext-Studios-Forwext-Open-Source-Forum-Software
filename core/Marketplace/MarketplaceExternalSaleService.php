<?php

declare(strict_types=1);

namespace Forwext\Core\Marketplace;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Audit\AuditAction;
use Forwext\Core\Audit\AuditEvent;
use Forwext\Core\Audit\AuditRecorder;
use Forwext\Core\Audit\AuditRequestId;
use Forwext\Core\Audit\AuditScope;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;
use Throwable;

final readonly class MarketplaceExternalSaleService
{
    public function __construct(
        private MarketplaceExternalSaleRepository $repository,
        private MarketplaceService $marketplace,
        private MarketplaceExternalSaleUrlPolicy $urlPolicy,
        private AuditRecorder $audit,
    ){}

    /** @return list<string> */
    public function allowedHosts():array{return $this->urlPolicy->allowedHosts();}

    /** @return array{listing:MarketplaceListing,link:?MarketplaceExternalSaleLink,click_count:int} */
    public function managementSnapshot(EntityId $actor,EntityId $listingId):array
    {
        $listing=$this->marketplace->managementListing($actor,$listingId);
        $this->marketplace->requireExternalLinkUse($actor);
        return [
            'listing'=>$listing,
            'link'=>$this->repository->link($listingId),
            'click_count'=>$this->repository->clickCount($listingId),
        ];
    }

    public function saveConfiguration(
        EntityId $actor,
        EntityId $listingId,
        ?string $targetUrl,
        bool $enabled,
        DateTimeImmutable $now,
        ?AuditRequestId $requestId=null,
    ):?MarketplaceExternalSaleLink{
        $this->marketplace->managementListing($actor,$listingId);
        $this->marketplace->requireExternalLinkUse($actor);
        $before=$this->repository->link($listingId);
        $at=self::utc($now);

        if($targetUrl===null||trim($targetUrl)===''){
            $event=$this->auditEvent($actor,$listingId,$before,null,$at,$requestId);
            $this->audit->mutate($event,fn():mixed=>$this->repository->delete($listingId));
            return null;
        }

        $validated=$this->urlPolicy->validate($targetUrl);
        $link=new MarketplaceExternalSaleLink(
            $listingId,$validated['url'],$validated['host'],$enabled,$actor,$before?->createdAt??$at,$at
        );
        $event=$this->auditEvent($actor,$listingId,$before,$link,$at,$requestId);
        $this->audit->mutate($event,fn():mixed=>$this->repository->save($link));
        return $link;
    }

    public function publicLink(EntityId $listingId,?EntityId $viewer):?MarketplaceExternalSaleLink
    {
        $listing=$this->marketplace->listing($listingId,$viewer);
        if($listing->state!==MarketplaceListingState::Active)return null;
        $link=$this->repository->link($listingId);
        if($link===null||!$link->enabled)return null;
        try{
            $validated=$this->urlPolicy->validate($link->targetUrl);
            if(!hash_equals($validated['host'],$link->targetHost))return null;
        }catch(InvalidArgumentException){
            return null;
        }
        return $link;
    }

    public function redirectTarget(EntityId $listingId,?EntityId $viewer,DateTimeImmutable $now):string
    {
        $link=$this->publicLink($listingId,$viewer)
            ?? throw new InvalidArgumentException('Marketplace external-sale link is unavailable.');
        $target=$this->urlPolicy->outboundUrl($link,$listingId);
        try{
            $this->repository->recordClick($listingId,$viewer,$link->targetHost,self::utc($now));
        }catch(Throwable){
            // Analytics must not turn a valid external-sale handoff into a checkout-blocking error.
        }
        return $target;
    }

    private function auditEvent(
        EntityId $actor,
        EntityId $listingId,
        ?MarketplaceExternalSaleLink $before,
        ?MarketplaceExternalSaleLink $after,
        DateTimeImmutable $at,
        ?AuditRequestId $requestId,
    ):AuditEvent{
        return new AuditEvent(
            AuditEvent::generateId(),AuditScope::Administration,$actor,
            AuditAction::fromString($after===null?'marketplace.external_link.remove':'marketplace.external_link.update'),
            'marketplace.external_sale',$listingId->value(),null,
            $after===null?'marketplace.external_link.remove':'marketplace.external_link.update',
            $requestId??AuditRequestId::generate(),
            self::snapshot($before),self::snapshot($after),$at
        );
    }

    /** @return array<string,scalar|null> */
    private static function snapshot(?MarketplaceExternalSaleLink $link):array
    {
        if($link===null)return [];
        return ['host'=>$link->targetHost,'enabled'=>$link->enabled];
    }

    private static function utc(DateTimeImmutable $at):DateTimeImmutable
    {
        return $at->setTimezone(new DateTimeZone('UTC'));
    }
}
