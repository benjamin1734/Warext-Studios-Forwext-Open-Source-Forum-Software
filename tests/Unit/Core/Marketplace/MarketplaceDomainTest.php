<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Marketplace;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Audit\AuditEvent;
use Forwext\Core\Audit\AuditRecorder;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDefinition;
use Forwext\Core\Domain\Access\Permission\PermissionEffect;
use Forwext\Core\Domain\Access\Permission\PermissionEngine;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Access\Permission\PermissionRule;
use Forwext\Core\Domain\Access\Permission\PermissionRuleRepository;
use Forwext\Core\Domain\Access\Permission\PermissionSubjectType;
use Forwext\Core\Domain\Access\Permission\PermissionValueType;
use Forwext\Core\Domain\Access\UserAccessAssignment;
use Forwext\Core\Domain\Access\UserAccessAssignmentProvider;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Marketplace\MarketplaceBillingSnapshot;
use Forwext\Core\Marketplace\MarketplaceCategory;
use Forwext\Core\Marketplace\MarketplaceCustomFieldDefinition;
use Forwext\Core\Marketplace\MarketplaceDeliveryState;
use Forwext\Core\Marketplace\MarketplaceCustomFieldType;
use Forwext\Core\Marketplace\MarketplaceListing;
use Forwext\Core\Marketplace\MarketplaceListingCard;
use Forwext\Core\Marketplace\MarketplaceListingPromotion;
use Forwext\Core\Marketplace\MarketplaceListingQuery;
use Forwext\Core\Marketplace\MarketplaceListingSort;
use Forwext\Core\Marketplace\MarketplaceListingState;
use Forwext\Core\Marketplace\MarketplaceMedia;
use Forwext\Core\Marketplace\MarketplaceOrder;
use Forwext\Core\Marketplace\MarketplaceOrderItem;
use Forwext\Core\Marketplace\MarketplaceOrderState;
use Forwext\Core\Marketplace\MarketplacePaymentState;
use Forwext\Core\Marketplace\MarketplacePurchaseRepository;
use Forwext\Core\Marketplace\MarketplacePurchaseService;
use Forwext\Core\Marketplace\MarketplaceMoney;
use Forwext\Core\Marketplace\MarketplaceRepository;
use Forwext\Core\Marketplace\MarketplaceReview;
use Forwext\Core\Marketplace\MarketplaceReviewState;
use Forwext\Core\Marketplace\MarketplaceReviewSummary;
use Forwext\Core\Marketplace\MarketplaceService;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MarketplaceDomainTest extends TestCase
{
    public function testMoneyAndMediaRejectUnsafeValues():void
    {
        try{
            new MarketplaceMoney(-1,'TRY');
            self::fail('Negative price must be rejected.');
        }catch(InvalidArgumentException){}

        try{
            new MarketplaceMoney(100,'try');
            self::fail('Currency must use the canonical uppercase representation.');
        }catch(InvalidArgumentException){}

        $listingId=EntityId::fromString(str_repeat('a',32));
        $this->expectException(InvalidArgumentException::class);
        new MarketplaceListing(
            $listingId,UserId::generate(),EntityId::fromString(str_repeat('b',32)),
            'safe-listing','Safe listing','Description',new MarketplaceMoney(12500,'TRY'),[],
            [new MarketplaceMedia(
                EntityId::fromString(str_repeat('c',32)),
                'marketplace/'.str_repeat('d',32).'/'.str_repeat('e',64).'.webp',
                'image/webp','',0
            )],
            [],MarketplaceListingState::Draft,$this->at('2026-09-19 19:00:00'),$this->at('2026-09-19 19:00:00')
        );
    }

    public function testCategoryCycleIsRejectedBeforePersistence():void
    {
        $repo=new MemoryMarketplaceRepository();
        $manager=UserId::generate();
        $a=$this->category(str_repeat('1',32),null,'aa','a-category');
        $b=$this->category(str_repeat('2',32),$a->categoryId,'bb','b-category');
        $repo->saveCategory($a);
        $repo->saveCategory($b);

        $service=$this->service($repo,[
            $manager->value()=>['marketplace.category.manage'=>true],
        ]);

        $cycle=new MarketplaceCategory(
            $a->categoryId,$b->categoryId,'aa','a-category','A','',true,10,$a->createdAt,$this->at('2026-09-19 19:05:00')
        );

        $this->expectException(InvalidArgumentException::class);
        $service->saveCategory($manager,$cycle,$this->at('2026-09-19 19:05:00'));
    }

    public function testRequiredTypedCustomFieldAndListingLifecycleAreBackendAuthoritative():void
    {
        $repo=new MemoryMarketplaceRepository();
        $seller=UserId::generate();
        $staff=UserId::generate();
        $category=$this->category(str_repeat('3',32),null,'games','games');
        $repo->saveCategory($category);
        $field=new MarketplaceCustomFieldDefinition(
            EntityId::fromString(str_repeat('4',32)),$category->categoryId,'condition','Durum',
            MarketplaceCustomFieldType::Select,true,['new','used'],10,true
        );
        $repo->saveCustomField($field);

        $service=$this->service($repo,[
            $seller->value()=>[
                'marketplace.listing.view'=>true,
                'marketplace.listing.create'=>true,
                'marketplace.listing.manage_own'=>true,
                'marketplace.listing.manage_all'=>false,
            ],
            $staff->value()=>[
                'marketplace.listing.view'=>true,
                'marketplace.listing.manage_all'=>true,
            ],
        ]);

        $id=MarketplaceListing::generateId();
        $missing=$this->listing($id,$seller,$category->categoryId,[]);
        try{
            $service->saveListing($seller,$missing);
            self::fail('Required marketplace custom field must be enforced.');
        }catch(InvalidArgumentException){}

        $draft=$this->listing($id,$seller,$category->categoryId,['condition'=>'used']);
        $saved=$service->saveListing($seller,$draft);
        self::assertSame(MarketplaceListingState::Draft,$saved->state);

        $pending=$service->submit($seller,$id,$this->at('2026-09-19 19:10:00'));
        self::assertSame(MarketplaceListingState::Pending,$pending->state);

        try{
            $service->approve($seller,$id,$this->at('2026-09-19 19:11:00'));
            self::fail('Seller cannot approve own marketplace listing.');
        }catch(PermissionDeniedException){}

        $active=$service->approve($staff,$id,$this->at('2026-09-19 19:12:00'));
        self::assertSame(MarketplaceListingState::Active,$active->state);
        self::assertTrue($active->state->publicVisible());
        self::assertSame(['condition'=>'used'],$active->customValues);
    }

    public function testRequiredTextValueCannotBeEmptyAndFieldCategoryIsImmutable():void
    {
        $repo=new MemoryMarketplaceRepository();
        $manager=UserId::generate();
        $seller=UserId::generate();
        $categoryA=$this->category(str_repeat('6',32),null,'hardware','hardware');
        $categoryB=$this->category(str_repeat('7',32),null,'software','software');
        $repo->saveCategory($categoryA);
        $repo->saveCategory($categoryB);
        $field=new MarketplaceCustomFieldDefinition(
            EntityId::fromString(str_repeat('8',32)),$categoryA->categoryId,'license','Lisans',
            MarketplaceCustomFieldType::Text,true,[],10,true
        );
        $repo->saveCustomField($field);

        $service=$this->service($repo,[
            $seller->value()=>['marketplace.listing.create'=>true,'marketplace.listing.manage_own'=>true],
            $manager->value()=>['marketplace.category.manage'=>true],
        ]);

        try{
            $service->saveListing($seller,$this->listing(
                MarketplaceListing::generateId(),$seller,$categoryA->categoryId,['license'=>'   ']
            ));
            self::fail('Required text custom field cannot normalize to empty.');
        }catch(InvalidArgumentException){}

        $moved=new MarketplaceCustomFieldDefinition(
            $field->fieldId,$categoryB->categoryId,$field->key,$field->label,$field->type,
            $field->required,$field->options,$field->sortOrder,$field->active
        );
        $this->expectException(InvalidArgumentException::class);
        $service->saveCustomField($manager,$moved,$this->at('2026-09-19 19:30:00'));
    }

    public function testReviewSelfAbusePromotionPermissionAndReviewUpdateAreEnforced():void
    {
        $repo=new MemoryMarketplaceRepository();
        $seller=UserId::generate();
        $buyer=UserId::generate();
        $staff=UserId::generate();
        $category=$this->category(str_repeat('9',32),null,'market','market');
        $repo->saveCategory($category);
        $service=$this->service($repo,[
            $seller->value()=>[
                'marketplace.listing.create'=>true,'marketplace.listing.manage_own'=>true,
                'marketplace.review.create'=>true,'marketplace.listing.view'=>true,
            ],
            $buyer->value()=>[
                'marketplace.review.create'=>true,'marketplace.listing.view'=>true,
                'marketplace.feature.manage'=>false,
            ],
            $staff->value()=>[
                'marketplace.listing.manage_all'=>true,'marketplace.listing.view'=>true,
                'marketplace.feature.manage'=>true,'marketplace.review.manage'=>true,
            ],
        ]);
        $id=MarketplaceListing::generateId();
        $service->saveListing($seller,$this->listing($id,$seller,$category->categoryId,[]));
        $service->submit($seller,$id,$this->at('2026-09-20 09:00:00'));
        $service->approve($staff,$id,$this->at('2026-09-20 09:01:00'));

        try{
            $service->saveReview($seller,$id,5,'Kendi ilanım',$this->at('2026-09-20 09:02:00'));
            self::fail('Seller must not review own listing.');
        }catch(InvalidArgumentException){}

        $first=$service->saveReview($buyer,$id,4,'Gayet iyi',$this->at('2026-09-20 09:03:00'));
        $second=$service->saveReview($buyer,$id,5,'Fikrimi güncelledim',$this->at('2026-09-20 09:04:00'));
        self::assertSame($first->reviewId->value(),$second->reviewId->value());
        self::assertSame(1,$repo->reviewSummary($id)->count);
        self::assertSame(5,$repo->reviewSummary($id)->ratingTotal);

        try{
            $service->setPromotion($buyer,$id,$this->at('2026-09-21 09:00:00'),null,$this->at('2026-09-20 09:05:00'));
            self::fail('Ordinary buyer cannot feature listings.');
        }catch(PermissionDeniedException){}

        $promotion=$service->setPromotion(
            $staff,$id,$this->at('2026-09-21 09:00:00'),$this->at('2026-09-20 18:00:00'),
            $this->at('2026-09-20 09:05:00')
        );
        self::assertNotNull($promotion->featuredUntil);
        self::assertNotNull($repo->promotion($id));
    }

    public function testSellerIdentityAndDirectStateEditsAreImmutable():void
    {
        $repo=new MemoryMarketplaceRepository();
        $seller=UserId::generate();
        $other=UserId::generate();
        $category=$this->category(str_repeat('5',32),null,'services','services');
        $repo->saveCategory($category);
        $service=$this->service($repo,[
            $seller->value()=>[
                'marketplace.listing.create'=>true,'marketplace.listing.manage_own'=>true,
            ],
        ]);
        $id=MarketplaceListing::generateId();
        $draft=$this->listing($id,$seller,$category->categoryId,[]);
        $service->saveListing($seller,$draft);

        $changedSeller=$this->listing($id,$other,$category->categoryId,[]);
        try{
            $service->saveListing($seller,$changedSeller);
            self::fail('Marketplace seller identity must be immutable.');
        }catch(InvalidArgumentException){}

        $directState=new MarketplaceListing(
            $draft->listingId,$draft->sellerUserId,$draft->categoryId,$draft->slug,$draft->title,$draft->description,
            $draft->price,$draft->tags,$draft->media,$draft->customValues,MarketplaceListingState::Active,
            $draft->createdAt,$this->at('2026-09-19 19:20:00')
        );
        $this->expectException(InvalidArgumentException::class);
        $service->saveListing($seller,$directState);
    }

    public function testExternalSalePermissionUsesSharedEngine():void
    {
        $repo=new MemoryMarketplaceRepository();
        $allowed=UserId::generate();
        $denied=UserId::generate();
        $service=$this->service($repo,[
            $allowed->value()=>['marketplace.external_link.use'=>true],
            $denied->value()=>['marketplace.external_link.use'=>false],
        ]);

        self::assertTrue($service->canUseExternalLink($allowed));
        self::assertFalse($service->canUseExternalLink($denied));
        $this->expectException(PermissionDeniedException::class);
        $service->requireExternalLinkUse($denied);
    }

    public function testNativePurchaseCheckoutIsIdempotentAndSnapshotsListingData():void
    {
        $repo=new MemoryMarketplaceRepository();
        $purchases=new MemoryMarketplacePurchaseRepository();
        $seller=UserId::generate();
        $buyer=UserId::generate();
        $staff=UserId::generate();
        $stranger=UserId::generate();
        $category=$this->category(str_repeat('4',32),null,'native','native');
        $repo->saveCategory($category);

        $permissions=[
            $seller->value()=>[
                'marketplace.listing.create'=>true,'marketplace.listing.manage_own'=>true,
                'marketplace.listing.view'=>true,'marketplace.internal_purchase.use'=>true,
                'marketplace.purchase'=>true,
            ],
            $buyer->value()=>['marketplace.listing.view'=>true,'marketplace.purchase'=>true],
            $staff->value()=>[
                'marketplace.listing.view'=>true,'marketplace.listing.manage_all'=>true,
                'marketplace.order.manage'=>true,
            ],
            $stranger->value()=>['marketplace.listing.view'=>true,'marketplace.purchase'=>true],
        ];
        $marketplace=$this->service($repo,$permissions);
        $purchaseService=new MarketplacePurchaseService(
            new MarketplaceTestDatabase(),$purchases,$marketplace,new MarketplaceAudit()
        );

        $listingId=MarketplaceListing::generateId();
        $marketplace->saveListing($seller,$this->listing($listingId,$seller,$category->categoryId,[]));
        $marketplace->submit($seller,$listingId,$this->at('2026-09-21 10:00:00'));
        $active=$marketplace->approve($staff,$listingId,$this->at('2026-09-21 10:01:00'));

        $purchaseService->setInternalSale($seller,$listingId,true,$this->at('2026-09-21 10:02:00'));
        self::assertTrue($purchaseService->canPurchaseListing($buyer,$active));

        try{
            $purchaseService->addToCart($seller,$listingId,$this->at('2026-09-21 10:03:00'));
            self::fail('Seller must not purchase own listing.');
        }catch(InvalidArgumentException){}

        $purchaseService->addToCart($buyer,$listingId,$this->at('2026-09-21 10:04:00'));
        self::assertCount(1,$purchaseService->cart($buyer));

        $billing=new MarketplaceBillingSnapshot('Batın Test','buyer@example.com','TR',null,'Çanakkale');
        $checkoutKey=str_repeat('a',32);
        $orders=$purchaseService->checkout($buyer,$billing,$checkoutKey,$this->at('2026-09-21 10:05:00'));
        self::assertCount(1,$orders);
        $order=$orders[0];
        self::assertSame(250000,$order->totalMinor);
        self::assertSame(MarketplaceOrderState::Pending,$order->state);
        self::assertSame(MarketplacePaymentState::Pending,$order->paymentState);
        self::assertSame(MarketplaceDeliveryState::Pending,$order->deliveryState);
        self::assertSame($seller->value(),$order->sellerUserId->value());
        self::assertSame([],$purchaseService->cart($buyer));

        $retry=$purchaseService->checkout($buyer,$billing,$checkoutKey,$this->at('2026-09-21 10:06:00'));
        self::assertCount(1,$retry);
        self::assertSame($order->orderId->value(),$retry[0]->orderId->value());

        $items=$purchaseService->order($buyer,$order->orderId)['items'];
        self::assertCount(1,$items);
        self::assertSame(250000,$items[0]->unitMinor);
        self::assertSame('Marketplace item',$items[0]->title);

        $changed=new MarketplaceListing(
            $active->listingId,$active->sellerUserId,$active->categoryId,$active->slug,'Changed title',
            $active->description,new MarketplaceMoney(999999,'TRY'),$active->tags,$active->media,$active->customValues,
            MarketplaceListingState::Active,$active->createdAt,$this->at('2026-09-21 10:07:00')
        );
        $marketplace->saveListing($seller,$changed);
        $snapshot=$purchaseService->order($buyer,$order->orderId);
        self::assertSame(250000,$snapshot['order']->totalMinor);
        self::assertSame('Marketplace item',$snapshot['items'][0]->title);

        try{
            $purchaseService->order($stranger,$order->orderId);
            self::fail('Unrelated users must not read marketplace orders.');
        }catch(InvalidArgumentException){}

        self::assertSame($order->orderId->value(),$purchaseService->order($staff,$order->orderId)['order']->orderId->value());
        self::assertCount(1,$purchaseService->orders($staff));
    }

    public function testNativeCheckoutSplitsOrdersBySellerAndCurrency():void
    {
        $repo=new MemoryMarketplaceRepository();
        $purchases=new MemoryMarketplacePurchaseRepository();
        $sellerA=UserId::generate();
        $sellerB=UserId::generate();
        $buyer=UserId::generate();
        $staff=UserId::generate();
        $category=$this->category(str_repeat('2',32),null,'split','split');
        $repo->saveCategory($category);
        $permissions=[
            $sellerA->value()=>[
                'marketplace.listing.create'=>true,'marketplace.listing.manage_own'=>true,
                'marketplace.listing.view'=>true,'marketplace.internal_purchase.use'=>true,
            ],
            $sellerB->value()=>[
                'marketplace.listing.create'=>true,'marketplace.listing.manage_own'=>true,
                'marketplace.listing.view'=>true,'marketplace.internal_purchase.use'=>true,
            ],
            $buyer->value()=>['marketplace.listing.view'=>true,'marketplace.purchase'=>true],
            $staff->value()=>['marketplace.listing.view'=>true,'marketplace.listing.manage_all'=>true],
        ];
        $marketplace=$this->service($repo,$permissions);
        $purchaseService=new MarketplacePurchaseService(
            new MarketplaceTestDatabase(),$purchases,$marketplace,new MarketplaceAudit()
        );

        $idA=MarketplaceListing::generateId();
        $idB=MarketplaceListing::generateId();
        $marketplace->saveListing($sellerA,$this->listing($idA,$sellerA,$category->categoryId,[]));
        $marketplace->saveListing($sellerB,$this->listing($idB,$sellerB,$category->categoryId,[]));
        foreach([[$sellerA,$idA],[$sellerB,$idB]] as [$seller,$id]){
            $marketplace->submit($seller,$id,$this->at('2026-09-21 12:00:00'));
            $marketplace->approve($staff,$id,$this->at('2026-09-21 12:01:00'));
            $purchaseService->setInternalSale($seller,$id,true,$this->at('2026-09-21 12:02:00'));
            $purchaseService->addToCart($buyer,$id,$this->at('2026-09-21 12:03:00'));
        }

        $orders=$purchaseService->checkout(
            $buyer,new MarketplaceBillingSnapshot('Buyer Test','buyer2@example.com','TR'),
            str_repeat('b',32),$this->at('2026-09-21 12:04:00')
        );
        self::assertCount(2,$orders);
        $sellerIds=array_map(static fn(MarketplaceOrder $order):string=>$order->sellerUserId->value(),$orders);
        sort($sellerIds,SORT_STRING);
        $expected=[$sellerA->value(),$sellerB->value()];
        sort($expected,SORT_STRING);
        self::assertSame($expected,$sellerIds);
    }

    public function testStaleCartDoesNotExposeNonPublicListingDetails():void
    {
        $repo=new MemoryMarketplaceRepository();
        $purchases=new MemoryMarketplacePurchaseRepository();
        $seller=UserId::generate();
        $buyer=UserId::generate();
        $staff=UserId::generate();
        $category=$this->category(str_repeat('3',32),null,'stale','stale');
        $repo->saveCategory($category);
        $marketplace=$this->service($repo,[
            $seller->value()=>[
                'marketplace.listing.create'=>true,'marketplace.listing.manage_own'=>true,
                'marketplace.listing.view'=>true,'marketplace.internal_purchase.use'=>true,
            ],
            $buyer->value()=>['marketplace.listing.view'=>true,'marketplace.purchase'=>true],
            $staff->value()=>['marketplace.listing.view'=>true,'marketplace.listing.manage_all'=>true],
        ]);
        $purchaseService=new MarketplacePurchaseService(
            new MarketplaceTestDatabase(),$purchases,$marketplace,new MarketplaceAudit()
        );

        $listingId=MarketplaceListing::generateId();
        $marketplace->saveListing($seller,$this->listing($listingId,$seller,$category->categoryId,[]));
        $marketplace->submit($seller,$listingId,$this->at('2026-09-21 11:00:00'));
        $marketplace->approve($staff,$listingId,$this->at('2026-09-21 11:01:00'));
        $purchaseService->setInternalSale($seller,$listingId,true,$this->at('2026-09-21 11:02:00'));
        $purchaseService->addToCart($buyer,$listingId,$this->at('2026-09-21 11:03:00'));

        $marketplace->pause($seller,$listingId,$this->at('2026-09-21 11:04:00'));
        $cart=$purchaseService->cart($buyer);
        self::assertCount(1,$cart);
        self::assertFalse($cart[0]->purchasable);
        self::assertNull($cart[0]->listing);
    }

    private function category(string $id,?EntityId $parent,string $key,string $slug):MarketplaceCategory
    {
        $at=$this->at('2026-09-19 18:00:00');
        return new MarketplaceCategory(
            EntityId::fromString($id),$parent,$key,$slug,strtoupper($key),'',true,10,$at,$at
        );
    }

    /** @param array<string,string|int|bool> $custom */
    private function listing(EntityId $id,EntityId $seller,EntityId $category,array $custom):MarketplaceListing
    {
        $at=$this->at('2026-09-19 19:00:00');
        return new MarketplaceListing(
            $id,$seller,$category,'listing-'.$id->value(),'Marketplace item','A marketplace listing description.',
            new MarketplaceMoney(250000,'TRY'),['digital'],[],$custom,MarketplaceListingState::Draft,$at,$at
        );
    }

    /** @param array<string,array<string,bool>> $permissions */
    private function service(MemoryMarketplaceRepository $repo,array $permissions):MarketplaceService
    {
        return new MarketplaceService(
            new MarketplaceTestDatabase(),$repo,
            new PermissionAuthorizer(
                new PermissionEngine(new MarketplacePermissionRules($permissions)),
                new MarketplaceAssignments(array_keys($permissions))
            ),
            new MarketplaceAudit()
        );
    }

    private function at(string $value):DateTimeImmutable
    {
        return new DateTimeImmutable($value,new DateTimeZone('UTC'));
    }
}

final class MemoryMarketplaceRepository implements MarketplaceRepository
{
    /** @var array<string,MarketplaceCategory> */
    private array $categories=[];
    /** @var array<string,MarketplaceCustomFieldDefinition> */
    private array $fields=[];
    /** @var array<string,MarketplaceListing> */
    private array $listings=[];
    /** @var list<array{listing:string,action:string,from:?string,to:?string}> */
    public array $history=[];
    /** @var array<string,MarketplaceListingPromotion> */
    private array $promotions=[];
    /** @var array<string,MarketplaceReview> */
    private array $reviews=[];

    public function categories(bool $enabledOnly=true):array
    {
        return array_values(array_filter(
            $this->categories,static fn(MarketplaceCategory $c):bool=>!$enabledOnly||$c->enabled
        ));
    }
    public function category(EntityId $categoryId):?MarketplaceCategory{return $this->categories[$categoryId->value()]??null;}
    public function saveCategory(MarketplaceCategory $category):void{$this->categories[$category->categoryId->value()]=$category;}
    public function customFields(EntityId $categoryId,bool $activeOnly=true):array
    {
        return array_values(array_filter(
            $this->fields,
            static fn(MarketplaceCustomFieldDefinition $f):bool=>
                $f->categoryId->equals($categoryId)&&(!$activeOnly||$f->active)
        ));
    }
    public function customField(EntityId $fieldId):?MarketplaceCustomFieldDefinition{return $this->fields[$fieldId->value()]??null;}
    public function saveCustomField(MarketplaceCustomFieldDefinition $field):void{$this->fields[$field->fieldId->value()]=$field;}
    public function listing(EntityId $listingId):?MarketplaceListing{return $this->listings[$listingId->value()]??null;}
    public function browse(MarketplaceListingQuery $query,int $limit=24,int $offset=0):array
    {
        $items=[];
        foreach($this->listings as $listing){
            if(!$listing->state->publicVisible())continue;
            if($query->sellerUserId!==null&&!$listing->sellerUserId->equals($query->sellerUserId))continue;
            if($query->categoryId!==null&&!$listing->categoryId->equals($query->categoryId))continue;
            $summary=$this->reviewSummary($listing->listingId);
            $promotion=$this->promotion($listing->listingId);
            $category=$this->categories[$listing->categoryId->value()]??null;
            $items[]=new MarketplaceListingCard(
                $listing->listingId,$listing->sellerUserId,$listing->categoryId,$listing->slug,$listing->title,
                $listing->price,$listing->state,'seller',$category?->name??'Category',
                $promotion?->featuredAt(new DateTimeImmutable('2026-09-20 09:00:00',new DateTimeZone('UTC')))??false,
                $promotion?->pinnedAt(new DateTimeImmutable('2026-09-20 09:00:00',new DateTimeZone('UTC')))??false,
                $listing->media[0]->mediaId??null,
                $summary->count,$summary->ratingTotal,$listing->updatedAt
            );
        }
        return array_slice($items,$offset,$limit);
    }
    public function browseCount(MarketplaceListingQuery $query):int{return count($this->browse($query,100,0));}
    public function promotion(EntityId $listingId):?MarketplaceListingPromotion{return $this->promotions[$listingId->value()]??null;}
    public function savePromotion(MarketplaceListingPromotion $promotion):void{$this->promotions[$promotion->listingId->value()]=$promotion;}
    public function review(EntityId $reviewId):?MarketplaceReview{return $this->reviews[$reviewId->value()]??null;}
    public function reviewForUser(EntityId $listingId,EntityId $userId):?MarketplaceReview
    {
        foreach($this->reviews as $review)if($review->listingId->equals($listingId)&&$review->reviewerUserId->equals($userId))return $review;
        return null;
    }
    public function reviews(EntityId $listingId,bool $visibleOnly=true,int $limit=100,int $offset=0):array
    {
        $items=array_values(array_filter($this->reviews,static fn(MarketplaceReview $r):bool=>
            $r->listingId->equals($listingId)&&(!$visibleOnly||$r->state===MarketplaceReviewState::Visible)
        ));
        return array_slice($items,$offset,$limit);
    }
    public function reviewSummary(EntityId $listingId):MarketplaceReviewSummary
    {
        $count=0;$total=0;
        foreach($this->reviews($listingId,true,100,0) as $review){++$count;$total+=$review->rating;}
        return new MarketplaceReviewSummary($count,$total);
    }
    public function saveReview(MarketplaceReview $review):void
    {
        foreach($this->reviews as $id=>$existing){
            if($existing->listingId->equals($review->listingId)&&$existing->reviewerUserId->equals($review->reviewerUserId)&&$id!==$review->reviewId->value()){
                unset($this->reviews[$id]);
            }
        }
        $this->reviews[$review->reviewId->value()]=$review;
    }
    public function sellerListings(EntityId $sellerUserId,bool $publicOnly=false,int $limit=100):array
    {
        $items=array_values(array_filter(
            $this->listings,
            static fn(MarketplaceListing $l):bool=>$l->sellerUserId->equals($sellerUserId)&&(!$publicOnly||$l->state->publicVisible())
        ));
        return array_slice($items,0,$limit);
    }
    public function manageListings(?EntityId $sellerUserId=null,?MarketplaceListingState $state=null,int $limit=100):array
    {
        $items=array_values(array_filter($this->listings,static fn(MarketplaceListing $l):bool=>
            ($sellerUserId===null||$l->sellerUserId->equals($sellerUserId))&&($state===null||$l->state===$state)
        ));
        return array_slice($items,0,$limit);
    }
    public function saveListing(MarketplaceListing $listing):void{$this->listings[$listing->listingId->value()]=$listing;}
    public function recordHistory(EntityId $listingId,EntityId $actor,string $action,?string $fromState,?string $toState):void
    {
        $this->history[]=['listing'=>$listingId->value(),'action'=>$action,'from'=>$fromState,'to'=>$toState];
    }
}

final class MarketplacePermissionRules implements PermissionRuleRepository
{
    /** @param array<string,array<string,bool>> $permissions */
    public function __construct(private array $permissions){}
    public function definition(PermissionKey $key):?PermissionDefinition
    {
        return new PermissionDefinition($key,PermissionValueType::Flag);
    }
    public function rules(PermissionKey $key,UserAccessAssignment $assignment,?EntityId $nodeId):array
    {
        $allowed=$this->permissions[$assignment->userId()->value()][$key->value()]??false;
        return [new PermissionRule(
            PermissionSubjectType::User,$assignment->userId(),
            $allowed?PermissionEffect::Allow:PermissionEffect::Deny
        )];
    }
}

final class MarketplaceAssignments implements UserAccessAssignmentProvider
{
    /** @var array<string,true> */
    private array $ids;
    /** @param list<string> $ids */
    public function __construct(array $ids){$this->ids=array_fill_keys($ids,true);}
    public function find(EntityId $userId):?UserAccessAssignment
    {
        return isset($this->ids[$userId->value()])
            ?new UserAccessAssignment($userId,EntityId::fromString(str_repeat('f',32)))
            :null;
    }
}

final class MarketplaceAudit implements AuditRecorder
{
    /** @var list<AuditEvent> */
    public array $events=[];
    public function append(AuditEvent $event):void{$this->events[]=$event;}
    public function mutate(AuditEvent $event,callable $mutation):mixed
    {
        $result=$mutation();$this->events[]=$event;return $result;
    }
}

final class MarketplaceTestDatabase implements TransactionalQueryExecutor
{
    private int $depth=0;
    public function execute(CompiledQuery $query):int{return 1;}
    public function fetchOne(CompiledQuery $query):?array{return null;}
    public function fetchAll(CompiledQuery $query):array{return [];}
    public function fetchValue(CompiledQuery $query):mixed{return null;}
    public function inTransaction():bool{return $this->depth>0;}
    public function transaction(Closure $callback):mixed
    {
        ++$this->depth;
        try{return $callback($this);}finally{--$this->depth;}
    }
}


final class MemoryMarketplacePurchaseRepository implements MarketplacePurchaseRepository
{
    /** @var array<string,bool> */
    private array $internal=[];
    /** @var array<string,array<string,EntityId>> */
    private array $carts=[];
    /** @var array<string,MarketplaceOrder> */
    private array $ordersById=[];
    /** @var array<string,list<MarketplaceOrderItem>> */
    private array $items=[];
    /** @var list<array{order:string,action:string}> */
    public array $history=[];

    public function internalSaleEnabled(EntityId $listingId):bool{return $this->internal[$listingId->value()]??false;}
    public function saveInternalSaleSetting(EntityId $listingId,bool $enabled,EntityId $actor,DateTimeImmutable $at):void
    {
        $this->internal[$listingId->value()]=$enabled;
    }
    public function cartListingIds(EntityId $buyer,bool $forUpdate=false):array
    {
        return array_values($this->carts[$buyer->value()]??[]);
    }
    public function cartCount(EntityId $buyer):int{return count($this->carts[$buyer->value()]??[]);}
    public function addCartItem(EntityId $buyer,EntityId $listingId,DateTimeImmutable $at):void
    {
        $this->carts[$buyer->value()][$listingId->value()]=$listingId;
    }
    public function removeCartItem(EntityId $buyer,EntityId $listingId):void
    {
        unset($this->carts[$buyer->value()][$listingId->value()]);
    }
    public function clearCart(EntityId $buyer):void{$this->carts[$buyer->value()]=[];}
    public function ordersForCheckout(EntityId $buyer,string $checkoutKey):array
    {
        return array_values(array_filter(
            $this->ordersById,
            static fn(MarketplaceOrder $order):bool=>$order->buyerUserId->equals($buyer)&&$order->checkoutKey===$checkoutKey
        ));
    }
    public function createOrder(MarketplaceOrder $order,array $items):void
    {
        $this->ordersById[$order->orderId->value()]=$order;
        $this->items[$order->orderId->value()]=$items;
    }
    public function order(EntityId $orderId):?MarketplaceOrder{return $this->ordersById[$orderId->value()]??null;}
    public function orderItems(EntityId $orderId):array{return $this->items[$orderId->value()]??[];}
    public function ordersForUser(EntityId $userId,int $limit=100):array
    {
        return array_slice(array_values(array_filter(
            $this->ordersById,
            static fn(MarketplaceOrder $order):bool=>$order->buyerUserId->equals($userId)||$order->sellerUserId->equals($userId)
        )),0,$limit);
    }
    public function orders(int $limit=100):array{return array_slice(array_values($this->ordersById),0,$limit);}
    public function saveOrderStates(MarketplaceOrder $order):void{$this->ordersById[$order->orderId->value()]=$order;}
    public function recordOrderHistory(
        EntityId $orderId,EntityId $actor,string $action,
        MarketplaceOrderState $fromOrderState,MarketplaceOrderState $toOrderState,
        MarketplacePaymentState $fromPaymentState,MarketplacePaymentState $toPaymentState,
        MarketplaceDeliveryState $fromDeliveryState,MarketplaceDeliveryState $toDeliveryState,
        DateTimeImmutable $at,
    ):void{
        $this->history[]=['order'=>$orderId->value(),'action'=>$action];
    }
}
