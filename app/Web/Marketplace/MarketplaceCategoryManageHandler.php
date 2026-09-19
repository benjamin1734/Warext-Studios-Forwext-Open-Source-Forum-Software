<?php

declare(strict_types=1);

namespace Forwext\App\Web\Marketplace;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Audit\HttpAuditRequestId;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Marketplace\MarketplaceCategory;
use Forwext\Core\Marketplace\MarketplaceCustomFieldDefinition;
use Forwext\Core\Marketplace\MarketplaceCustomFieldType;
use Forwext\Core\Marketplace\MarketplaceService;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;
use ValueError;

final readonly class MarketplaceCategoryManageHandler implements RequestHandlerInterface
{
    public function __construct(
        private MarketplaceService $marketplace,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
    ){}

    public function handle(Request $request):Response
    {
        $actor=$this->viewers->resolve($request);
        if($actor===null){
            return Response::text('Authentication required.',401)->withHeader('Cache-Control','no-store');
        }

        try{
            if($request->method()===HttpMethod::Post){
                $selection=$this->mutate($actor,$request);
                $location='/admin/marketplace/categories?updated=1';
                if($selection['category']!==null)$location.='&category='.rawurlencode($selection['category']->value());
                if($selection['field']!==null)$location.='&field='.rawurlencode($selection['field']->value());
                return Response::text('',303)
                    ->withHeader('Location',$this->basePath->prepend($location))
                    ->withHeader('Cache-Control','no-store');
            }

            $token=$request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
            if(!is_string($token)||$token===''){
                return Response::text('Internal Server Error',500)->withHeader('Cache-Control','no-store');
            }

            $snapshot=$this->marketplace->managementSnapshot($actor);
            $selectedCategory=null;
            $selectedField=null;

            $categoryRaw=$request->query()['category']??null;
            if(is_string($categoryRaw)&&$categoryRaw!==''){
                $selectedCategory=$this->marketplace->managementCategory($actor,self::id($categoryRaw));
                if($selectedCategory===null)return Response::text('Not Found',404)->withHeader('Cache-Control','no-store');
            }

            $fieldRaw=$request->query()['field']??null;
            if(is_string($fieldRaw)&&$fieldRaw!==''){
                $selectedField=$this->marketplace->managementCustomField($actor,self::id($fieldRaw));
                if($selectedField===null)return Response::text('Not Found',404)->withHeader('Cache-Control','no-store');
                if($selectedCategory===null){
                    $selectedCategory=$this->marketplace->managementCategory($actor,$selectedField->categoryId);
                }
            }

            return Response::html(MarketplaceCategoryHtml::manage(
                $snapshot['categories'],$snapshot['fields'],$selectedCategory,$selectedField,
                $this->basePath,$token,($request->query()['updated']??null)==='1'
            ))->withHeader('Cache-Control','private, no-store');
        }catch(PermissionDeniedException){
            return Response::text('Forbidden',403)->withHeader('Cache-Control','no-store');
        }catch(InvalidArgumentException|ValueError){
            return Response::text('Bad Request',400)->withHeader('Cache-Control','no-store');
        }
    }

    /** @return array{category:?EntityId,field:?EntityId} */
    private function mutate(EntityId $actor,Request $request):array
    {
        $body=$request->parsedBody();
        $action=$body['action']??null;
        if(!is_string($action))throw new InvalidArgumentException('Marketplace management action is missing.');
        $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));

        if($action==='category_save'){
            $raw=self::optional($body,'category_id',32);
            $existing=$raw===null?null:$this->marketplace->managementCategory($actor,self::id($raw));
            if($raw!==null&&$existing===null)throw new InvalidArgumentException('Marketplace category does not exist.');
            $parentRaw=self::optional($body,'parent_category_id',32);
            $category=new MarketplaceCategory(
                $existing?->categoryId??MarketplaceCategory::generateId(),
                $parentRaw===null?null:self::id($parentRaw),
                strtolower(self::required($body,'key',64)),
                strtolower(self::required($body,'slug',96)),
                self::required($body,'name',120),
                self::optional($body,'description',4000)??'',
                self::checked($body,'enabled'),
                self::integer($body,'sort_order',0,65535,100),
                $existing?->createdAt??$now,
                $now,
            );
            $this->marketplace->saveCategory(
                $actor,$category,$now,HttpAuditRequestId::fromRequest($request)
            );
            return ['category'=>$category->categoryId,'field'=>null];
        }

        if($action==='field_save'){
            $raw=self::optional($body,'field_id',32);
            $existing=$raw===null?null:$this->marketplace->managementCustomField($actor,self::id($raw));
            if($raw!==null&&$existing===null)throw new InvalidArgumentException('Marketplace custom field does not exist.');
            $categoryId=self::id(self::required($body,'category_id',32));
            $type=MarketplaceCustomFieldType::from(self::required($body,'field_type',16));
            $options=[];
            if($type===MarketplaceCustomFieldType::Select){
                $rawOptions=self::optional($body,'options',12000)??'';
                foreach(preg_split('/\R/u',$rawOptions)?:[] as $option){
                    $option=trim($option);
                    if($option!=='')$options[]=$option;
                }
            }
            $field=new MarketplaceCustomFieldDefinition(
                $existing?->fieldId??MarketplaceCustomFieldDefinition::generateId(),
                $categoryId,
                strtolower(self::required($body,'key',64)),
                self::required($body,'label',120),
                $type,
                self::checked($body,'required'),
                $options,
                self::integer($body,'sort_order',0,1000,100),
                self::checked($body,'active'),
            );
            $this->marketplace->saveCustomField(
                $actor,$field,$now,HttpAuditRequestId::fromRequest($request)
            );
            return ['category'=>$categoryId,'field'=>$field->fieldId];
        }

        throw new InvalidArgumentException('Unknown marketplace management action.');
    }

    private static function id(mixed $value):EntityId
    {
        if(!is_string($value)||preg_match('/^[a-f0-9]{32}$/D',$value)!==1){
            throw new InvalidArgumentException('Marketplace entity id is invalid.');
        }
        return EntityId::fromString($value);
    }

    /** @param array<string,mixed> $body */
    private static function required(array $body,string $key,int $max):string
    {
        $value=$body[$key]??null;
        if(!is_string($value)||trim($value)===''||strlen(trim($value))>$max){
            throw new InvalidArgumentException('Marketplace field is invalid: '.$key);
        }
        return trim($value);
    }

    /** @param array<string,mixed> $body */
    private static function optional(array $body,string $key,int $max):?string
    {
        $value=$body[$key]??null;
        if($value===null||$value==='')return null;
        if(!is_string($value)||strlen($value)>$max){
            throw new InvalidArgumentException('Marketplace optional field is invalid: '.$key);
        }
        $value=trim($value);
        return $value===''?null:$value;
    }

    /** @param array<string,mixed> $body */
    private static function integer(array $body,string $key,int $min,int $max,int $default):int
    {
        if(!array_key_exists($key,$body)||$body[$key]==='')return $default;
        $value=filter_var($body[$key],FILTER_VALIDATE_INT);
        if(!is_int($value)||$value<$min||$value>$max){
            throw new InvalidArgumentException('Marketplace integer field is invalid: '.$key);
        }
        return $value;
    }

    /** @param array<string,mixed> $body */
    private static function checked(array $body,string $key):bool
    {
        return ($body[$key]??null)==='1'||($body[$key]??null)===1||($body[$key]??null)===true;
    }
}
