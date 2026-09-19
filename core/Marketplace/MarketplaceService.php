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
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class MarketplaceService
{
    public function __construct(
        private TransactionalQueryExecutor $database,
        private MarketplaceRepository $repository,
        private PermissionAuthorizer $authorizer,
        private AuditRecorder $audit,
    ){}

    /** @return list<MarketplaceCategory> */
    public function categories(?EntityId $actor=null,bool $includeDisabled=false):array
    {
        if($actor!==null)$this->require($actor,'marketplace.listing.view');
        if($includeDisabled){
            if($actor===null)$this->denyAnonymous();
            $this->require($actor,'marketplace.category.manage');
        }
        return $this->repository->categories(!$includeDisabled);
    }

    /** @return array{categories:list<MarketplaceCategory>,fields:array<string,list<MarketplaceCustomFieldDefinition>>} */
    public function managementSnapshot(EntityId $actor):array
    {
        $this->require($actor,'marketplace.category.manage');
        $categories=$this->repository->categories(false);
        $fields=[];
        foreach($categories as $category){
            $fields[$category->categoryId->value()]=$this->repository->customFields($category->categoryId,false);
        }
        return ['categories'=>$categories,'fields'=>$fields];
    }

    public function managementCategory(EntityId $actor,EntityId $categoryId):?MarketplaceCategory
    {
        $this->require($actor,'marketplace.category.manage');
        return $this->repository->category($categoryId);
    }

    public function managementCustomField(EntityId $actor,EntityId $fieldId):?MarketplaceCustomFieldDefinition
    {
        $this->require($actor,'marketplace.category.manage');
        return $this->repository->customField($fieldId);
    }

    public function saveCategory(EntityId $actor,MarketplaceCategory $category,DateTimeImmutable $now,?AuditRequestId $requestId=null):void
    {
        $this->require($actor,'marketplace.category.manage');
        $this->assertCategoryHierarchy($category);
        $before=$this->repository->category($category->categoryId);
        $event=new AuditEvent(
            AuditEvent::generateId(),AuditScope::Administration,$actor,
            AuditAction::fromString($before===null?'marketplace.category.create':'marketplace.category.update'),
            'marketplace.category',$category->categoryId->value(),null,
            $before===null?'marketplace.category.create':'marketplace.category.update',
            $requestId??AuditRequestId::generate(),
            $before===null?[]:self::categorySnapshot($before),self::categorySnapshot($category),self::utc($now)
        );
        $this->audit->mutate($event,fn():mixed=>$this->repository->saveCategory($category));
    }

    public function saveCustomField(
        EntityId $actor,MarketplaceCustomFieldDefinition $field,DateTimeImmutable $now,?AuditRequestId $requestId=null
    ):void{
        $this->require($actor,'marketplace.category.manage');
        if($this->repository->category($field->categoryId)===null)throw new InvalidArgumentException('Marketplace custom field category was not found.');
        $before=$this->repository->customField($field->fieldId);
        if($before!==null&&!$before->categoryId->equals($field->categoryId)){
            throw new InvalidArgumentException('Marketplace custom field category is immutable after creation.');
        }
        $event=new AuditEvent(
            AuditEvent::generateId(),AuditScope::Administration,$actor,
            AuditAction::fromString($before===null?'marketplace.field.create':'marketplace.field.update'),
            'marketplace.custom_field',$field->fieldId->value(),null,
            $before===null?'marketplace.field.create':'marketplace.field.update',
            $requestId??AuditRequestId::generate(),
            $before===null?[]:['category_id'=>$before->categoryId->value(),'key'=>$before->key,'active'=>$before->active],
            ['category_id'=>$field->categoryId->value(),'key'=>$field->key,'type'=>$field->type->value,'required'=>$field->required,'active'=>$field->active],
            self::utc($now)
        );
        $this->audit->mutate($event,fn():mixed=>$this->repository->saveCustomField($field));
    }

    public function listing(EntityId $listingId,?EntityId $actor):MarketplaceListing
    {
        $listing=$this->repository->listing($listingId)??throw new InvalidArgumentException('Marketplace listing was not found.');
        if($listing->state->publicVisible()){
            if($actor!==null)$this->require($actor,'marketplace.listing.view');
            return $listing;
        }
        if($actor===null||!$this->canManage($actor,$listing)){
            throw new InvalidArgumentException('Marketplace listing is unavailable.');
        }
        return $listing;
    }

    /** @return list<MarketplaceListing> */
    public function sellerListings(EntityId $sellerUserId,?EntityId $actor,int $limit=100):array
    {
        UserId::assert($sellerUserId);
        if($actor===null)return $this->repository->sellerListings($sellerUserId,true,$limit);
        if($actor->equals($sellerUserId)&&$this->allows($actor,'marketplace.listing.manage_own')){
            return $this->repository->sellerListings($sellerUserId,false,$limit);
        }
        $this->require($actor,'marketplace.listing.view');
        return $this->repository->sellerListings($sellerUserId,true,$limit);
    }

    public function saveListing(EntityId $actor,MarketplaceListing $candidate):MarketplaceListing
    {
        $existing=$this->repository->listing($candidate->listingId);
        if($existing===null){
            $this->require($actor,'marketplace.listing.create');
            if(!$candidate->sellerUserId->equals($actor))$this->require($actor,'marketplace.listing.manage_all');
            if($candidate->state!==MarketplaceListingState::Draft)throw new InvalidArgumentException('New marketplace listing must start as draft.');
        }else{
            if(!$this->canManage($actor,$existing))$this->require($actor,'marketplace.listing.manage_all');
            if(!$existing->sellerUserId->equals($candidate->sellerUserId))throw new InvalidArgumentException('Marketplace seller is immutable.');
            if($candidate->state!==$existing->state)throw new InvalidArgumentException('Marketplace state changes require transition workflow.');
        }

        $category=$this->repository->category($candidate->categoryId);
        if($category===null||!$category->enabled)throw new InvalidArgumentException('Marketplace category is unavailable.');
        $custom=$this->normalizeCustomValues($candidate->categoryId,$candidate->customValues);

        $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
        $listing=new MarketplaceListing(
            $candidate->listingId,$candidate->sellerUserId,$candidate->categoryId,$candidate->slug,
            trim($candidate->title),trim($candidate->description),$candidate->price,$candidate->tags,$candidate->media,
            $custom,$existing?->state??MarketplaceListingState::Draft,$existing?->createdAt??$candidate->createdAt,$now
        );
        $this->database->transaction(function()use($listing,$actor,$existing):void{
            $this->repository->saveListing($listing);
            $this->repository->recordHistory(
                $listing->listingId,$actor,$existing===null?'listing.create':'listing.update',
                $existing?->state->value,$listing->state->value
            );
        });
        return $listing;
    }

    public function submit(EntityId $actor,EntityId $listingId,DateTimeImmutable $now):MarketplaceListing
    {
        return $this->transition($actor,$listingId,MarketplaceListingState::Pending,$now,false,'listing.submit');
    }

    public function approve(EntityId $actor,EntityId $listingId,DateTimeImmutable $now,?AuditRequestId $requestId=null):MarketplaceListing
    {
        $this->require($actor,'marketplace.listing.manage_all');
        $listing=$this->transition($actor,$listingId,MarketplaceListingState::Active,$now,true,'listing.approve');
        $this->audit->append(new AuditEvent(
            AuditEvent::generateId(),AuditScope::Administration,$actor,AuditAction::fromString('marketplace.listing.approve'),
            'marketplace.listing',$listingId->value(),null,'marketplace.listing.approve',$requestId??AuditRequestId::generate(),
            ['state'=>MarketplaceListingState::Pending->value],['state'=>MarketplaceListingState::Active->value],self::utc($now)
        ));
        return $listing;
    }

    public function pause(EntityId $actor,EntityId $listingId,DateTimeImmutable $now):MarketplaceListing
    {
        return $this->transition($actor,$listingId,MarketplaceListingState::Paused,$now,false,'listing.pause');
    }

    public function markSold(EntityId $actor,EntityId $listingId,DateTimeImmutable $now):MarketplaceListing
    {
        return $this->transition($actor,$listingId,MarketplaceListingState::Sold,$now,false,'listing.sold');
    }

    public function close(EntityId $actor,EntityId $listingId,DateTimeImmutable $now):MarketplaceListing
    {
        return $this->transition($actor,$listingId,MarketplaceListingState::Closed,$now,false,'listing.close');
    }

    public function archive(EntityId $actor,EntityId $listingId,DateTimeImmutable $now,?AuditRequestId $requestId=null):MarketplaceListing
    {
        $this->require($actor,'marketplace.listing.manage_all');
        $before=$this->repository->listing($listingId)??throw new InvalidArgumentException('Marketplace listing was not found.');
        $listing=$this->transition($actor,$listingId,MarketplaceListingState::Archived,$now,true,'listing.archive');
        $this->audit->append(new AuditEvent(
            AuditEvent::generateId(),AuditScope::Administration,$actor,AuditAction::fromString('marketplace.listing.archive'),
            'marketplace.listing',$listingId->value(),null,'marketplace.listing.archive',$requestId??AuditRequestId::generate(),
            ['state'=>$before->state->value],['state'=>$listing->state->value],self::utc($now)
        ));
        return $listing;
    }

    private function transition(
        EntityId $actor,EntityId $listingId,MarketplaceListingState $target,DateTimeImmutable $now,bool $staff,string $action
    ):MarketplaceListing{
        $existing=$this->repository->listing($listingId)??throw new InvalidArgumentException('Marketplace listing was not found.');
        if($staff)$this->require($actor,'marketplace.listing.manage_all');
        elseif(!$this->canManage($actor,$existing))$this->require($actor,'marketplace.listing.manage_all');

        $allowed=match($target){
            MarketplaceListingState::Pending=>in_array($existing->state,[MarketplaceListingState::Draft,MarketplaceListingState::Paused],true),
            MarketplaceListingState::Active=>$staff&&$existing->state===MarketplaceListingState::Pending,
            MarketplaceListingState::Paused=>$existing->state===MarketplaceListingState::Active,
            MarketplaceListingState::Sold=>in_array($existing->state,[MarketplaceListingState::Active,MarketplaceListingState::Paused],true),
            MarketplaceListingState::Closed=>in_array($existing->state,[MarketplaceListingState::Draft,MarketplaceListingState::Pending,MarketplaceListingState::Active,MarketplaceListingState::Paused],true),
            MarketplaceListingState::Archived=>$staff&&in_array($existing->state,[MarketplaceListingState::Sold,MarketplaceListingState::Closed],true),
            MarketplaceListingState::Draft=>false,
        };
        if(!$allowed)throw new InvalidArgumentException('Marketplace listing state transition is not allowed.');

        $updated=new MarketplaceListing(
            $existing->listingId,$existing->sellerUserId,$existing->categoryId,$existing->slug,$existing->title,
            $existing->description,$existing->price,$existing->tags,$existing->media,$existing->customValues,$target,
            $existing->createdAt,self::utc($now)
        );
        $this->database->transaction(function()use($updated,$actor,$existing,$action):void{
            $this->repository->saveListing($updated);
            $this->repository->recordHistory($updated->listingId,$actor,$action,$existing->state->value,$updated->state->value);
        });
        return $updated;
    }

    /** @param array<string,string|int|bool> $values @return array<string,string|int|bool> */
    private function normalizeCustomValues(EntityId $categoryId,array $values):array
    {
        $definitions=$this->repository->customFields($categoryId,true);
        $byKey=[];
        foreach($definitions as $definition)$byKey[$definition->key]=$definition;

        foreach($values as $key=>$value){
            if(!isset($byKey[$key]))throw new InvalidArgumentException('Marketplace listing contains an unknown custom field.');
            $values[$key]=$byKey[$key]->normalize($value);
        }
        foreach($definitions as $definition){
            if(!$definition->required)continue;
            if(!array_key_exists($definition->key,$values)){
                throw new InvalidArgumentException('Marketplace listing is missing a required custom field.');
            }
            $value=$values[$definition->key];
            if(is_string($value)&&trim($value)===''){
                throw new InvalidArgumentException('Marketplace required custom field cannot be empty.');
            }
        }
        return $values;
    }

    private function assertCategoryHierarchy(MarketplaceCategory $category):void
    {
        $seen=[$category->categoryId->value()=>true];
        $parentId=$category->parentCategoryId;
        $depth=0;
        while($parentId!==null){
            if(isset($seen[$parentId->value()]))throw new InvalidArgumentException('Marketplace category hierarchy contains a cycle.');
            $seen[$parentId->value()]=true;
            $parent=$this->repository->category($parentId)??throw new InvalidArgumentException('Marketplace parent category was not found.');
            $parentId=$parent->parentCategoryId;
            if(++$depth>8)throw new InvalidArgumentException('Marketplace category hierarchy is too deep.');
        }
    }

    private function canManage(EntityId $actor,MarketplaceListing $listing):bool
    {
        return $this->allows($actor,'marketplace.listing.manage_all')
            ||($listing->sellerUserId->equals($actor)&&$this->allows($actor,'marketplace.listing.manage_own'));
    }

    private function require(EntityId $actor,string $permission):void
    {
        $decision=$this->authorizer->resolve($actor,PermissionKey::fromString($permission));
        if(!$decision->isAllowed())throw new PermissionDeniedException($decision);
    }

    private function allows(EntityId $actor,string $permission):bool
    {
        return $this->authorizer->allows($actor,PermissionKey::fromString($permission));
    }

    private function denyAnonymous():never{throw new InvalidArgumentException('Authentication required for disabled marketplace categories.');}

    /** @return array<string,scalar|null> */
    private static function categorySnapshot(MarketplaceCategory $category):array
    {
        return [
            'parent_id'=>$category->parentCategoryId?->value(),'key'=>$category->key,'slug'=>$category->slug,
            'enabled'=>$category->enabled,'sort_order'=>$category->sortOrder
        ];
    }

    private static function utc(DateTimeImmutable $at):DateTimeImmutable{return $at->setTimezone(new DateTimeZone('UTC'));}
}
