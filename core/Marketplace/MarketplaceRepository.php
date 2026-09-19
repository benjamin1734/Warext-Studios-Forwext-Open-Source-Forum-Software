<?php

declare(strict_types=1);

namespace Forwext\Core\Marketplace;

use Forwext\Core\Domain\Entity\EntityId;

interface MarketplaceRepository
{
    /** @return list<MarketplaceCategory> */
    public function categories(bool $enabledOnly=true):array;
    public function category(EntityId $categoryId):?MarketplaceCategory;
    public function saveCategory(MarketplaceCategory $category):void;

    /** @return list<MarketplaceCustomFieldDefinition> */
    public function customFields(EntityId $categoryId,bool $activeOnly=true):array;
    public function customField(EntityId $fieldId):?MarketplaceCustomFieldDefinition;
    public function saveCustomField(MarketplaceCustomFieldDefinition $field):void;

    public function listing(EntityId $listingId):?MarketplaceListing;
    /** @return list<MarketplaceListing> */
    public function sellerListings(EntityId $sellerUserId,bool $publicOnly=false,int $limit=100):array;
    public function saveListing(MarketplaceListing $listing):void;
    public function recordHistory(EntityId $listingId,EntityId $actor,string $action,?string $fromState,?string $toState):void;
}
