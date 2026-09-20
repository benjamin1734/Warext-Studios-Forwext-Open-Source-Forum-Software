<?php

declare(strict_types=1);

namespace Forwext\Core\Marketplace;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final readonly class DatabaseMarketplaceRepository implements MarketplaceRepository
{
    public function __construct(private TransactionalQueryExecutor $database){}

    public function categories(bool $enabledOnly=true):array
    {
        $rows=$this->database->fetchAll(new CompiledQuery(
            'SELECT * FROM forwext_marketplace_categories'.($enabledOnly?' WHERE enabled=1':'')
            .' ORDER BY sort_order,name,category_id'
        ));
        return array_map($this->hydrateCategory(...),$rows);
    }

    public function category(EntityId $categoryId):?MarketplaceCategory
    {
        $row=$this->database->fetchOne(new CompiledQuery(
            'SELECT * FROM forwext_marketplace_categories WHERE category_id=:id LIMIT 1',
            ['id'=>$categoryId->value()]
        ));
        return $row===null?null:$this->hydrateCategory($row);
    }

    public function saveCategory(MarketplaceCategory $category):void
    {
        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_marketplace_categories '
            . '(category_id,parent_category_id,category_key,slug,name,description,enabled,sort_order,created_at_utc,updated_at_utc) '
            . 'VALUES (:id,:parent,:key,:slug,:name,:description,:enabled,:sort_order,:created,:updated) '
            . 'ON DUPLICATE KEY UPDATE parent_category_id=VALUES(parent_category_id),category_key=VALUES(category_key),slug=VALUES(slug),'
            . 'name=VALUES(name),description=VALUES(description),enabled=VALUES(enabled),sort_order=VALUES(sort_order),updated_at_utc=VALUES(updated_at_utc)',
            [
                'id'=>$category->categoryId->value(),'parent'=>$category->parentCategoryId?->value(),
                'key'=>$category->key,'slug'=>$category->slug,'name'=>$category->name,'description'=>$category->description,
                'enabled'=>$category->enabled?1:0,'sort_order'=>$category->sortOrder,
                'created'=>self::format($category->createdAt),'updated'=>self::format($category->updatedAt)
            ]
        ));
    }

    public function customFields(EntityId $categoryId,bool $activeOnly=true):array
    {
        $rows=$this->database->fetchAll(new CompiledQuery(
            'SELECT * FROM forwext_marketplace_custom_fields WHERE category_id=:category_id'
            .($activeOnly?' AND active=1':'').' ORDER BY sort_order,field_key,field_id',
            ['category_id'=>$categoryId->value()]
        ));
        return array_map($this->hydrateField(...),$rows);
    }

    public function customField(EntityId $fieldId):?MarketplaceCustomFieldDefinition
    {
        $row=$this->database->fetchOne(new CompiledQuery(
            'SELECT * FROM forwext_marketplace_custom_fields WHERE field_id=:id LIMIT 1',
            ['id'=>$fieldId->value()]
        ));
        return $row===null?null:$this->hydrateField($row);
    }

    public function saveCustomField(MarketplaceCustomFieldDefinition $field):void
    {
        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_marketplace_custom_fields '
            . '(field_id,category_id,field_key,label,field_type,required,options_json,sort_order,active,created_at_utc,updated_at_utc) '
            . 'VALUES (:id,:category,:key,:label,:type,:required,:options,:sort_order,:active,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) '
            . 'ON DUPLICATE KEY UPDATE category_id=VALUES(category_id),field_key=VALUES(field_key),label=VALUES(label),'
            . 'field_type=VALUES(field_type),required=VALUES(required),options_json=VALUES(options_json),sort_order=VALUES(sort_order),'
            . 'active=VALUES(active),updated_at_utc=UTC_TIMESTAMP(6)',
            [
                'id'=>$field->fieldId->value(),'category'=>$field->categoryId->value(),'key'=>$field->key,
                'label'=>$field->label,'type'=>$field->type->value,'required'=>$field->required?1:0,
                'options'=>json_encode($field->options,JSON_THROW_ON_ERROR),'sort_order'=>$field->sortOrder,'active'=>$field->active?1:0
            ]
        ));
    }

    public function listing(EntityId $listingId):?MarketplaceListing
    {
        $row=$this->database->fetchOne(new CompiledQuery(
            'SELECT * FROM forwext_marketplace_listings WHERE listing_id=:id LIMIT 1',
            ['id'=>$listingId->value()]
        ));
        return $row===null?null:$this->hydrateListing($row);
    }

    public function browse(MarketplaceListingQuery $query,int $limit=24,int $offset=0):array
    {
        self::page($limit,$offset);
        [$where,$params]=$this->browseWhere($query);
        $order=match($query->sort){
            MarketplaceListingSort::Newest=>'l.updated_at_utc DESC,l.listing_id DESC',
            MarketplaceListingSort::PriceAsc=>'l.price_minor ASC,l.updated_at_utc DESC,l.listing_id DESC',
            MarketplaceListingSort::PriceDesc=>'l.price_minor DESC,l.updated_at_utc DESC,l.listing_id DESC',
            MarketplaceListingSort::Rating=>'COALESCE(rv.average_rating,0) DESC,COALESCE(rv.review_count,0) DESC,l.updated_at_utc DESC,l.listing_id DESC',
            MarketplaceListingSort::Featured=>'IF(p.pinned_until_utc>UTC_TIMESTAMP(6),1,0) DESC,IF(p.featured_until_utc>UTC_TIMESTAMP(6),1,0) DESC,l.updated_at_utc DESC,l.listing_id DESC',
        };
        if($query->sort!==MarketplaceListingSort::Featured){
            $order='IF(p.pinned_until_utc>UTC_TIMESTAMP(6),1,0) DESC,'.$order;
        }

        $rows=$this->database->fetchAll(new CompiledQuery(
            'SELECT l.listing_id,l.seller_user_id,l.category_id,l.slug,l.title,l.price_minor,l.currency,l.state,l.updated_at_utc,'
            . 'u.username AS seller_username,c.name AS category_name,'
            . 'IF(p.featured_until_utc>UTC_TIMESTAMP(6),1,0) AS featured,'
            . 'IF(p.pinned_until_utc>UTC_TIMESTAMP(6),1,0) AS pinned,'
            . 'COALESCE(rv.review_count,0) AS review_count,COALESCE(rv.rating_total,0) AS rating_total '
            . 'FROM forwext_marketplace_listings l '
            . 'INNER JOIN forwext_users u ON u.user_id=l.seller_user_id '
            . 'INNER JOIN forwext_marketplace_categories c ON c.category_id=l.category_id '
            . 'LEFT JOIN forwext_marketplace_listing_promotions p ON p.listing_id=l.listing_id '
            . "LEFT JOIN (SELECT listing_id,COUNT(*) AS review_count,SUM(rating) AS rating_total,AVG(rating) AS average_rating "
            . "FROM forwext_marketplace_reviews WHERE state='visible' GROUP BY listing_id) rv ON rv.listing_id=l.listing_id "
            . 'WHERE '.$where.' ORDER BY '.$order.' LIMIT '.$limit.' OFFSET '.$offset,
            $params
        ));
        return array_map(static fn(array $row):MarketplaceListingCard=>new MarketplaceListingCard(
            EntityId::fromString((string)$row['listing_id']),
            UserId::fromStored((string)$row['seller_user_id']),
            EntityId::fromString((string)$row['category_id']),
            (string)$row['slug'],(string)$row['title'],
            new MarketplaceMoney((int)$row['price_minor'],(string)$row['currency']),
            MarketplaceListingState::from((string)$row['state']),
            (string)$row['seller_username'],(string)$row['category_name'],
            (bool)$row['featured'],(bool)$row['pinned'],
            (int)$row['review_count'],(int)$row['rating_total'],
            self::parse((string)$row['updated_at_utc'])
        ),$rows);
    }

    public function browseCount(MarketplaceListingQuery $query):int
    {
        [$where,$params]=$this->browseWhere($query);
        return (int)$this->database->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM forwext_marketplace_listings l '
            . 'LEFT JOIN forwext_marketplace_listing_promotions p ON p.listing_id=l.listing_id '
            . 'WHERE '.$where,
            $params
        ));
    }

    public function promotion(EntityId $listingId):?MarketplaceListingPromotion
    {
        $row=$this->database->fetchOne(new CompiledQuery(
            'SELECT listing_id,featured_until_utc,pinned_until_utc,updated_by_user_id,updated_at_utc '
            . 'FROM forwext_marketplace_listing_promotions WHERE listing_id=:id LIMIT 1',
            ['id'=>$listingId->value()]
        ));
        return $row===null?null:new MarketplaceListingPromotion(
            EntityId::fromString((string)$row['listing_id']),
            isset($row['featured_until_utc'])&&is_string($row['featured_until_utc'])?self::parse($row['featured_until_utc']):null,
            isset($row['pinned_until_utc'])&&is_string($row['pinned_until_utc'])?self::parse($row['pinned_until_utc']):null,
            UserId::fromStored((string)$row['updated_by_user_id']),
            self::parse((string)$row['updated_at_utc'])
        );
    }

    public function savePromotion(MarketplaceListingPromotion $promotion):void
    {
        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_marketplace_listing_promotions '
            . '(listing_id,featured_until_utc,pinned_until_utc,updated_by_user_id,updated_at_utc) '
            . 'VALUES (:listing,:featured,:pinned,:actor,:updated) '
            . 'ON DUPLICATE KEY UPDATE featured_until_utc=VALUES(featured_until_utc),pinned_until_utc=VALUES(pinned_until_utc),'
            . 'updated_by_user_id=VALUES(updated_by_user_id),updated_at_utc=VALUES(updated_at_utc)',
            [
                'listing'=>$promotion->listingId->value(),
                'featured'=>$promotion->featuredUntil===null?null:self::format($promotion->featuredUntil),
                'pinned'=>$promotion->pinnedUntil===null?null:self::format($promotion->pinnedUntil),
                'actor'=>$promotion->updatedByUserId->value(),
                'updated'=>self::format($promotion->updatedAt),
            ]
        ));
    }

    public function review(EntityId $reviewId):?MarketplaceReview
    {
        $row=$this->database->fetchOne(new CompiledQuery(
            'SELECT * FROM forwext_marketplace_reviews WHERE review_id=:id LIMIT 1',['id'=>$reviewId->value()]
        ));
        return $row===null?null:$this->hydrateReview($row);
    }

    public function reviewForUser(EntityId $listingId,EntityId $userId):?MarketplaceReview
    {
        UserId::assert($userId);
        $row=$this->database->fetchOne(new CompiledQuery(
            'SELECT * FROM forwext_marketplace_reviews WHERE listing_id=:listing AND reviewer_user_id=:user LIMIT 1',
            ['listing'=>$listingId->value(),'user'=>$userId->value()]
        ));
        return $row===null?null:$this->hydrateReview($row);
    }

    public function reviews(EntityId $listingId,bool $visibleOnly=true,int $limit=100,int $offset=0):array
    {
        self::page($limit,$offset);
        $rows=$this->database->fetchAll(new CompiledQuery(
            'SELECT * FROM forwext_marketplace_reviews WHERE listing_id=:listing'
            .($visibleOnly?" AND state='visible'":'')
            .' ORDER BY updated_at_utc DESC,review_id DESC LIMIT '.$limit.' OFFSET '.$offset,
            ['listing'=>$listingId->value()]
        ));
        return array_map($this->hydrateReview(...),$rows);
    }

    public function reviewSummary(EntityId $listingId):MarketplaceReviewSummary
    {
        $row=$this->database->fetchOne(new CompiledQuery(
            "SELECT COUNT(*) AS review_count,COALESCE(SUM(rating),0) AS rating_total "
            . "FROM forwext_marketplace_reviews WHERE listing_id=:listing AND state='visible'",
            ['listing'=>$listingId->value()]
        ));
        return new MarketplaceReviewSummary((int)($row['review_count']??0),(int)($row['rating_total']??0));
    }

    public function saveReview(MarketplaceReview $review):void
    {
        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_marketplace_reviews '
            . '(review_id,listing_id,reviewer_user_id,rating,body,state,created_at_utc,updated_at_utc) '
            . 'VALUES (:id,:listing,:reviewer,:rating,:body,:state,:created,:updated) '
            . 'ON DUPLICATE KEY UPDATE rating=VALUES(rating),body=VALUES(body),state=VALUES(state),updated_at_utc=VALUES(updated_at_utc)',
            [
                'id'=>$review->reviewId->value(),'listing'=>$review->listingId->value(),'reviewer'=>$review->reviewerUserId->value(),
                'rating'=>$review->rating,'body'=>$review->body,'state'=>$review->state->value,
                'created'=>self::format($review->createdAt),'updated'=>self::format($review->updatedAt)
            ]
        ));
    }

    public function sellerListings(EntityId $sellerUserId,bool $publicOnly=false,int $limit=100):array
    {
        UserId::assert($sellerUserId);
        if($limit<1||$limit>200)throw new InvalidArgumentException('Marketplace listing limit is invalid.');
        $rows=$this->database->fetchAll(new CompiledQuery(
            'SELECT * FROM forwext_marketplace_listings WHERE seller_user_id=:seller'
            .($publicOnly?" AND state IN ('active','sold')":'')
            .' ORDER BY updated_at_utc DESC,listing_id DESC LIMIT '.$limit,
            ['seller'=>$sellerUserId->value()]
        ));
        return array_map($this->hydrateListing(...),$rows);
    }

    public function manageListings(?EntityId $sellerUserId=null,?MarketplaceListingState $state=null,int $limit=100):array
    {
        if($limit<1||$limit>200)throw new InvalidArgumentException('Marketplace management listing limit is invalid.');
        $parts=[];$params=[];
        if($sellerUserId!==null){
            UserId::assert($sellerUserId);
            $parts[]='seller_user_id=:seller';
            $params['seller']=$sellerUserId->value();
        }
        if($state!==null){
            $parts[]='state=:state';
            $params['state']=$state->value;
        }
        $where=$parts===[]?'':' WHERE '.implode(' AND ',$parts);
        $rows=$this->database->fetchAll(new CompiledQuery(
            'SELECT * FROM forwext_marketplace_listings'.$where
            .' ORDER BY updated_at_utc DESC,listing_id DESC LIMIT '.$limit,
            $params
        ));
        return array_map($this->hydrateListing(...),$rows);
    }

    public function saveListing(MarketplaceListing $listing):void
    {
        $persist=function()use($listing):void{
            $this->database->execute(new CompiledQuery(
                'INSERT INTO forwext_marketplace_listings '
                . '(listing_id,seller_user_id,category_id,slug,title,description,price_minor,currency,state,created_at_utc,updated_at_utc) '
                . 'VALUES (:id,:seller,:category,:slug,:title,:description,:price,:currency,:state,:created,:updated) '
                . 'ON DUPLICATE KEY UPDATE category_id=VALUES(category_id),slug=VALUES(slug),title=VALUES(title),'
                . 'description=VALUES(description),price_minor=VALUES(price_minor),currency=VALUES(currency),state=VALUES(state),updated_at_utc=VALUES(updated_at_utc)',
                [
                    'id'=>$listing->listingId->value(),'seller'=>$listing->sellerUserId->value(),'category'=>$listing->categoryId->value(),
                    'slug'=>$listing->slug,'title'=>$listing->title,'description'=>$listing->description,
                    'price'=>$listing->price->minorUnits,'currency'=>$listing->price->currency,
                    'state'=>$listing->state->value,'created'=>self::format($listing->createdAt),'updated'=>self::format($listing->updatedAt)
                ]
            ));

            $id=['listing_id'=>$listing->listingId->value()];
            $this->database->execute(new CompiledQuery('DELETE FROM forwext_marketplace_listing_tags WHERE listing_id=:listing_id',$id));
            foreach($listing->tags as $tag){
                $this->database->execute(new CompiledQuery(
                    'INSERT INTO forwext_marketplace_listing_tags(listing_id,tag_key) VALUES (:listing_id,:tag)',
                    ['listing_id'=>$listing->listingId->value(),'tag'=>$tag]
                ));
            }

            $this->database->execute(new CompiledQuery('DELETE FROM forwext_marketplace_listing_media WHERE listing_id=:listing_id',$id));
            foreach($listing->media as $media){
                $this->database->execute(new CompiledQuery(
                    'INSERT INTO forwext_marketplace_listing_media(media_id,listing_id,storage_path,media_type,alt_text,sort_order) '
                    . 'VALUES (:media_id,:listing_id,:path,:media_type,:alt_text,:sort_order)',
                    [
                        'media_id'=>$media->mediaId->value(),'listing_id'=>$listing->listingId->value(),
                        'path'=>$media->storagePath,'media_type'=>$media->mediaType,'alt_text'=>$media->altText,'sort_order'=>$media->sortOrder
                    ]
                ));
            }

            $this->database->execute(new CompiledQuery('DELETE FROM forwext_marketplace_listing_custom_values WHERE listing_id=:listing_id',$id));
            $fields=[];
            foreach($this->customFields($listing->categoryId,false) as $field)$fields[$field->key]=$field;
            foreach($listing->customValues as $key=>$value){
                $field=$fields[$key]??null;
                if($field===null)throw new RuntimeException('Marketplace listing references an unknown custom field.');
                $this->database->execute(new CompiledQuery(
                    'INSERT INTO forwext_marketplace_listing_custom_values(listing_id,field_id,value_json) VALUES (:listing_id,:field_id,:value)',
                    [
                        'listing_id'=>$listing->listingId->value(),'field_id'=>$field->fieldId->value(),
                        'value'=>json_encode($value,JSON_THROW_ON_ERROR)
                    ]
                ));
            }
        };
        if($this->database->inTransaction())$persist();else $this->database->transaction(static fn()=>$persist());
    }

    public function recordHistory(EntityId $listingId,EntityId $actor,string $action,?string $fromState,?string $toState):void
    {
        UserId::assert($actor);
        if(preg_match('/^[a-z][a-z0-9._-]{1,63}$/D',$action)!==1)throw new InvalidArgumentException('Marketplace history action is invalid.');
        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_marketplace_history(history_id,listing_id,actor_user_id,action,from_state,to_state,created_at_utc) '
            . 'VALUES (:id,:listing_id,:actor,:action,:from_state,:to_state,UTC_TIMESTAMP(6))',
            [
                'id'=>bin2hex(random_bytes(16)),'listing_id'=>$listingId->value(),'actor'=>$actor->value(),
                'action'=>$action,'from_state'=>$fromState,'to_state'=>$toState
            ]
        ));
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function browseWhere(MarketplaceListingQuery $query):array
    {
        $parts=["l.state IN ('active','sold')"];
        $params=[];
        if($query->text!==null){
            $parts[]='LOCATE(LOWER(:text),LOWER(CONCAT(l.title," ",l.description)))>0';
            $params['text']=$query->text;
        }
        if($query->categoryId!==null){$parts[]='l.category_id=:category';$params['category']=$query->categoryId->value();}
        if($query->sellerUserId!==null){$parts[]='l.seller_user_id=:seller';$params['seller']=$query->sellerUserId->value();}
        if($query->currency!==null){$parts[]='l.currency=:currency';$params['currency']=$query->currency;}
        if($query->minPriceMinor!==null){$parts[]='l.price_minor>=:min_price';$params['min_price']=$query->minPriceMinor;}
        if($query->maxPriceMinor!==null){$parts[]='l.price_minor<=:max_price';$params['max_price']=$query->maxPriceMinor;}
        if($query->tag!==null){
            $parts[]='EXISTS(SELECT 1 FROM forwext_marketplace_listing_tags mt WHERE mt.listing_id=l.listing_id AND mt.tag_key=:tag)';
            $params['tag']=$query->tag;
        }
        if($query->featuredOnly)$parts[]='p.featured_until_utc>UTC_TIMESTAMP(6)';
        if($query->pinnedOnly)$parts[]='p.pinned_until_utc>UTC_TIMESTAMP(6)';
        return [implode(' AND ',$parts),$params];
    }

    /** @param array<string,mixed> $row */
    private function hydrateReview(array $row):MarketplaceReview
    {
        return new MarketplaceReview(
            EntityId::fromString((string)$row['review_id']),
            EntityId::fromString((string)$row['listing_id']),
            UserId::fromStored((string)$row['reviewer_user_id']),
            (int)$row['rating'],(string)$row['body'],
            MarketplaceReviewState::from((string)$row['state']),
            self::parse((string)$row['created_at_utc']),self::parse((string)$row['updated_at_utc'])
        );
    }

    private static function page(int $limit,int $offset):void
    {
        if($limit<1||$limit>100||$offset<0||$offset>10000){
            throw new InvalidArgumentException('Marketplace pagination is invalid.');
        }
    }

    /** @param array<string,mixed> $row */
    private function hydrateCategory(array $row):MarketplaceCategory
    {
        return new MarketplaceCategory(
            EntityId::fromString((string)$row['category_id']),
            isset($row['parent_category_id'])&&is_string($row['parent_category_id'])?EntityId::fromString($row['parent_category_id']):null,
            (string)$row['category_key'],(string)$row['slug'],(string)$row['name'],(string)$row['description'],
            (bool)$row['enabled'],(int)$row['sort_order'],self::parse((string)$row['created_at_utc']),self::parse((string)$row['updated_at_utc'])
        );
    }

    /** @param array<string,mixed> $row */
    private function hydrateField(array $row):MarketplaceCustomFieldDefinition
    {
        try{$options=json_decode((string)$row['options_json'],true,32,JSON_THROW_ON_ERROR);}
        catch(JsonException $e){throw new RuntimeException('Stored marketplace custom field options are invalid.',previous:$e);}
        if(!is_array($options))throw new RuntimeException('Stored marketplace custom field options must be a list.');
        return new MarketplaceCustomFieldDefinition(
            EntityId::fromString((string)$row['field_id']),EntityId::fromString((string)$row['category_id']),
            (string)$row['field_key'],(string)$row['label'],MarketplaceCustomFieldType::from((string)$row['field_type']),
            (bool)$row['required'],array_values($options),(int)$row['sort_order'],(bool)$row['active']
        );
    }

    /** @param array<string,mixed> $row */
    private function hydrateListing(array $row):MarketplaceListing
    {
        $id=EntityId::fromString((string)$row['listing_id']);
        $tags=array_map(
            static fn(array $tag):string=>(string)$tag['tag_key'],
            $this->database->fetchAll(new CompiledQuery(
                'SELECT tag_key FROM forwext_marketplace_listing_tags WHERE listing_id=:id ORDER BY tag_key',['id'=>$id->value()]
            ))
        );
        $media=array_map(
            static fn(array $m):MarketplaceMedia=>new MarketplaceMedia(
                EntityId::fromString((string)$m['media_id']),(string)$m['storage_path'],(string)$m['media_type'],
                (string)$m['alt_text'],(int)$m['sort_order']
            ),
            $this->database->fetchAll(new CompiledQuery(
                'SELECT media_id,storage_path,media_type,alt_text,sort_order FROM forwext_marketplace_listing_media '
                . 'WHERE listing_id=:id ORDER BY sort_order,media_id',['id'=>$id->value()]
            ))
        );
        $custom=[];
        foreach($this->database->fetchAll(new CompiledQuery(
            'SELECT f.field_key,v.value_json FROM forwext_marketplace_listing_custom_values v '
            . 'INNER JOIN forwext_marketplace_custom_fields f ON f.field_id=v.field_id WHERE v.listing_id=:id ORDER BY f.sort_order,f.field_key',
            ['id'=>$id->value()]
        )) as $v){
            try{$value=json_decode((string)$v['value_json'],true,8,JSON_THROW_ON_ERROR);}
            catch(JsonException $e){throw new RuntimeException('Stored marketplace custom value is invalid.',previous:$e);}
            if(!is_string($value)&&!is_int($value)&&!is_bool($value))throw new RuntimeException('Stored marketplace custom value type is invalid.');
            $custom[(string)$v['field_key']]=$value;
        }
        return new MarketplaceListing(
            $id,UserId::fromStored((string)$row['seller_user_id']),EntityId::fromString((string)$row['category_id']),
            (string)$row['slug'],(string)$row['title'],(string)$row['description'],
            new MarketplaceMoney((int)$row['price_minor'],(string)$row['currency']),
            $tags,$media,$custom,MarketplaceListingState::from((string)$row['state']),
            self::parse((string)$row['created_at_utc']),self::parse((string)$row['updated_at_utc'])
        );
    }

    private static function format(DateTimeImmutable $at):string{return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');}
    private static function parse(string $value):DateTimeImmutable
    {
        foreach(['!Y-m-d H:i:s.u','!Y-m-d H:i:s'] as $format){
            $date=DateTimeImmutable::createFromFormat($format,$value,new DateTimeZone('UTC'));
            if($date instanceof DateTimeImmutable)return $date;
        }
        throw new RuntimeException('Stored marketplace timestamp is invalid.');
    }
}
