<?php

declare(strict_types=1);

namespace Forwext\App\Web\Marketplace;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Audit\HttpAuditRequestId;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Marketplace\MarketplaceCustomFieldDefinition;
use Forwext\Core\Marketplace\MarketplaceCustomFieldType;
use Forwext\Core\Marketplace\MarketplaceListing;
use Forwext\Core\Marketplace\MarketplaceListingState;
use Forwext\Core\Marketplace\MarketplaceMoney;
use Forwext\Core\Marketplace\MarketplaceService;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;

final readonly class MarketplaceManageHandler implements RequestHandlerInterface
{
    public function __construct(
        private MarketplaceService $marketplace,private UserRepository $users,
        private ProfileViewerResolver $viewers,private BasePath $basePath
    ){}
    public function handle(Request $request):Response
    {
        $actor=$this->viewers->resolve($request);
        if($actor===null)return Response::text('Authentication required.',401)->withHeader('Cache-Control','no-store');
        try{
            if($request->method()===HttpMethod::Post){
                $listingId=$this->mutate($actor,$request);
                $location='/marketplace/manage?updated=1'.($listingId===null?'':'&listing='.$listingId->value());
                return Response::text('',303)->withHeader('Location',$this->basePath->prepend($location))->withHeader('Cache-Control','no-store');
            }
            $csrf=$request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
            if(!is_string($csrf)||$csrf==='')return Response::text('Internal Server Error',500)->withHeader('Cache-Control','no-store');
            $selected=null;$newCategory=null;$fields=[];$promotion=null;$reviews=[];$authors=[];
            $raw=$request->query()['listing']??null;
            if(is_string($raw)&&$raw!==''){
                $selected=$this->marketplace->managementListing($actor,self::id($raw));
                $fields=$this->marketplace->customFieldsForCategory($selected->categoryId,$actor);
                if($this->marketplace->canFeature($actor))$promotion=$this->marketplace->promotion($selected->listingId,$actor);
                if($this->marketplace->canModerateReviews($actor)){
                    $reviews=$this->marketplace->managementReviews($actor,$selected->listingId);
                    foreach($reviews as $review){
                        $u=$this->users->find($review->reviewerUserId);
                        if($u!==null)$authors[$review->reviewerUserId->value()]=$u->username()->display();
                    }
                }
            }else{
                $rawCategory=$request->query()['new_category']??null;
                if(is_string($rawCategory)&&$rawCategory!==''){
                    $newCategory=self::id($rawCategory);
                    $fields=$this->marketplace->customFieldsForCategory($newCategory,$actor);
                }
            }
            return Response::html(MarketplaceHtml::manage(
                $this->marketplace->manageableListings($actor,null,100),$this->marketplace->categories($actor),
                $selected,$newCategory,$fields,$promotion,$reviews,$authors,
                $this->marketplace->canCreate($actor),$this->marketplace->canManageAll($actor),
                $this->marketplace->canFeature($actor),$this->marketplace->canModerateReviews($actor),
                $this->marketplace->canUseExternalLink($actor),$this->marketplace->canUseInternalPurchase($actor),
                $this->basePath,$csrf,($request->query()['updated']??null)==='1'
            ))->withHeader('Cache-Control','private, no-store');
        }catch(PermissionDeniedException){return Response::text('Forbidden',403)->withHeader('Cache-Control','no-store');}
        catch(InvalidArgumentException){return Response::text('Bad Request',400)->withHeader('Cache-Control','no-store');}
    }

    private function mutate(EntityId $actor,Request $request):?EntityId
    {
        $body=$request->parsedBody();$action=$body['action']??null;
        if(!is_string($action))throw new InvalidArgumentException('Marketplace action is missing.');
        $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
        if($action==='listing_save'){
            $raw=self::optional($body,'listing_id',32);
            $existing=$raw===null?null:$this->marketplace->managementListing($actor,self::id($raw));
            $categoryId=self::id(self::required($body,'category_id',32));
            $fields=$this->marketplace->customFieldsForCategory($categoryId,$actor);
            $listing=new MarketplaceListing(
                $existing?->listingId??MarketplaceListing::generateId(),
                $existing?->sellerUserId??$actor,$categoryId,
                strtolower(self::required($body,'slug',160)),self::required($body,'title',180),
                self::required($body,'description',120000),
                new MarketplaceMoney(self::money(self::required($body,'price',32)),strtoupper(self::required($body,'currency',3))),
                self::tags(self::optional($body,'tags',4096)??''),
                $existing?->media??[],
                self::custom($body,$fields),
                $existing?->state??MarketplaceListingState::Draft,
                $existing?->createdAt??$now,$now
            );
            return $this->marketplace->saveListing($actor,$listing)->listingId;
        }
        $listingId=self::id(self::required($body,'listing_id',32));
        return match($action){
            'submit'=>$this->marketplace->submit($actor,$listingId,$now)->listingId,
            'approve'=>$this->marketplace->approve($actor,$listingId,$now,HttpAuditRequestId::fromRequest($request))->listingId,
            'pause'=>$this->marketplace->pause($actor,$listingId,$now)->listingId,
            'sold'=>$this->marketplace->markSold($actor,$listingId,$now)->listingId,
            'close'=>$this->marketplace->close($actor,$listingId,$now)->listingId,
            'archive'=>$this->marketplace->archive($actor,$listingId,$now,HttpAuditRequestId::fromRequest($request))->listingId,
            'promotion_save'=>$this->promotion($actor,$listingId,$body,$now,$request),
            'review_moderate'=>$this->review($actor,$body,$now,$request),
            default=>throw new InvalidArgumentException('Unknown marketplace action.'),
        };
    }

    /** @param array<string,mixed> $body */
    private function promotion(EntityId $actor,EntityId $listingId,array $body,DateTimeImmutable $now,Request $request):EntityId
    {
        $this->marketplace->setPromotion(
            $actor,$listingId,self::date(self::optional($body,'featured_until',32)),
            self::date(self::optional($body,'pinned_until',32)),$now,HttpAuditRequestId::fromRequest($request)
        );
        return $listingId;
    }

    /** @param array<string,mixed> $body */
    private function review(EntityId $actor,array $body,DateTimeImmutable $now,Request $request):EntityId
    {
        $reviewId=self::id(self::required($body,'review_id',32));
        $visible=($body['visible']??null)==='1';
        return $this->marketplace->moderateReview($actor,$reviewId,$visible,$now,HttpAuditRequestId::fromRequest($request))->listingId;
    }

    /** @param array<string,mixed> $body @param list<MarketplaceCustomFieldDefinition> $fields @return array<string,string|int|bool> */
    private static function custom(array $body,array $fields):array
    {
        $values=[];
        foreach($fields as $field){
            $key='custom_'.$field->key;$raw=$body[$key]??null;
            if($field->type===MarketplaceCustomFieldType::Boolean){$values[$field->key]=$raw==='1';continue;}
            if($raw===null||$raw===''){
                if($field->required)throw new InvalidArgumentException('Required marketplace custom field is missing.');
                continue;
            }
            if(!is_string($raw))throw new InvalidArgumentException('Marketplace custom field is invalid.');
            $values[$field->key]=$field->type===MarketplaceCustomFieldType::Integer
                ? self::integerString($raw):trim($raw);
        }
        return $values;
    }

    /** @return list<string> */
    private static function tags(string $value):array
    {
        if($value==='')return [];
        $tags=[];foreach(explode(',',$value) as $tag){$tag=strtolower(trim($tag));if($tag!=='')$tags[]=$tag;}
        return $tags;
    }
    private static function money(string $v):int
    {
        if(preg_match('/^(\d{1,13})(?:\.(\d{1,2}))?$/D',$v,$m)!==1)throw new InvalidArgumentException('Marketplace price is invalid.');
        return ((int)$m[1])*100+(int)str_pad($m[2]??'',2,'0');
    }
    private static function integerString(string $v):int
    {
        $i=filter_var($v,FILTER_VALIDATE_INT);if(!is_int($i))throw new InvalidArgumentException('Marketplace integer custom field is invalid.');return $i;
    }
    private static function date(?string $v):?DateTimeImmutable
    {
        if($v===null)return null;
        $d=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$v,new DateTimeZone('UTC'));
        return $d instanceof DateTimeImmutable&&$d->format('Y-m-d\TH:i')===$v?$d:throw new InvalidArgumentException('Marketplace date is invalid.');
    }
    private static function id(mixed $v):EntityId
    {
        if(!is_string($v)||preg_match('/^[a-f0-9]{32}$/D',$v)!==1)throw new InvalidArgumentException('Marketplace id is invalid.');
        return EntityId::fromString($v);
    }
    /** @param array<string,mixed> $body */
    private static function required(array $body,string $key,int $max):string
    {
        $v=$body[$key]??null;if(!is_string($v)||trim($v)===''||strlen(trim($v))>$max)throw new InvalidArgumentException('Marketplace field is invalid: '.$key);
        return trim($v);
    }
    /** @param array<string,mixed> $body */
    private static function optional(array $body,string $key,int $max):?string
    {
        $v=$body[$key]??null;if($v===null||$v==='')return null;
        if(!is_string($v)||strlen($v)>$max)throw new InvalidArgumentException('Marketplace optional field is invalid: '.$key);
        $v=trim($v);return $v===''?null:$v;
    }
}
