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

    /** @return list<MarketplaceListingCard> */
    public function browse(MarketplaceListingQuery $query,int $limit=24,int $offset=0):array;
    public function browseCount(MarketplaceListingQuery $query):int;

    public function promotion(EntityId $listingId):?MarketplaceListingPromotion;
    public function savePromotion(MarketplaceListingPromotion $promotion):void;

    public function review(EntityId $reviewId):?MarketplaceReview;
    public function reviewForUser(EntityId $listingId,EntityId $userId):?MarketplaceReview;
    /** @return list<MarketplaceReview> */
    public function reviews(EntityId $listingId,bool $visibleOnly=true,int $limit=100,int $offset=0):array;
    public function reviewSummary(EntityId $listingId):MarketplaceReviewSummary;
    public function saveReview(MarketplaceReview $review):void;
    /** @return list<MarketplaceListing> */
    public function sellerListings(EntityId $sellerUserId,bool $publicOnly=false,int $limit=100):array;
    public function saveListing(MarketplaceListing $listing):void;
    public function recordHistory(EntityId $listingId,EntityId $actor,string $action,?string $fromState,?string $toState):void;
}
