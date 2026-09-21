<?php

declare(strict_types=1);

namespace Forwext\App\Web\Marketplace;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Audit\HttpAuditRequestId;
use Forwext\App\Web\Forum\UploadedAttachmentReader;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Attachment\AttachmentQuotaPolicy;
use Forwext\Core\Http\HttpException;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Http\Upload\UploadedFile;
use Forwext\Core\Marketplace\Delivery\MarketplaceDeliveryService;
use Forwext\Core\Marketplace\Delivery\MarketplaceDeliveryType;
use Forwext\Core\Marketplace\MarketplaceService;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Routing\Router;
use InvalidArgumentException;
use ValueError;

final readonly class MarketplaceDeliveryManageHandler implements RequestHandlerInterface
{
    public function __construct(
        private MarketplaceDeliveryService $delivery,
        private MarketplaceService $marketplace,
        private ProfileViewerResolver $viewers,
        private UploadedAttachmentReader $uploads,
        private AttachmentQuotaPolicy $quota,
        private BasePath $basePath,
    ){}

    public function handle(Request $request):Response
    {
        $actor=$this->viewers->resolve($request);
        if($actor===null)return Response::text('Authentication required.',401)->withHeader('Cache-Control','no-store');
        $listingId=$this->listingId($request);
        if($listingId===null)return Response::text('Not Found',404)->withHeader('Cache-Control','no-store');

        try{
            if($request->method()===HttpMethod::Post){
                $this->mutate($actor,$listingId,$request);
                return Response::redirect(
                    $this->basePath->prepend('/marketplace/manage/delivery/'.$listingId->value().'?updated=1'),303
                )->withHeader('Cache-Control','no-store');
            }

            $listing=$this->marketplace->managementListing($actor,$listingId);
            $setting=$this->delivery->listingSetting($actor,$listingId);
            $keyCount=$this->delivery->availableKeyCount($actor,$listingId);
            $csrf=$request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
            if(!is_string($csrf)||$csrf==='')return Response::text('Internal Server Error',500)->withHeader('Cache-Control','no-store');

            return Response::html(MarketplaceDeliveryHtml::manage(
                $listing,$setting,$keyCount,$this->basePath,$csrf,($request->query()['updated']??null)==='1'
            ))->withHeader('Cache-Control','private, no-store')->withHeader('X-Robots-Tag','noindex,nofollow');
        }catch(PermissionDeniedException){
            return Response::text('Forbidden',403)->withHeader('Cache-Control','no-store');
        }catch(InvalidArgumentException|ValueError|HttpException){
            return Response::text('Bad Request',400)->withHeader('Cache-Control','no-store');
        }
    }

    private function mutate(EntityId $actor,EntityId $listingId,Request $request):void
    {
        $body=$request->parsedBody();
        $action=$body['action']??null;
        if(!is_string($action))throw new InvalidArgumentException('Marketplace delivery action is missing.');
        $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
        $requestId=HttpAuditRequestId::fromRequest($request);

        if($action==='configure'){
            $raw=$body['delivery_type']??null;
            if(!is_string($raw))throw new InvalidArgumentException('Marketplace delivery type is missing.');
            $type=MarketplaceDeliveryType::from($raw);
            if($type===MarketplaceDeliveryType::Download){
                throw new InvalidArgumentException('Download delivery must be configured through a verified upload.');
            }
            $this->delivery->configureListing($actor,$listingId,$type,null,$now,$requestId);
            return;
        }

        if($action==='add_keys'){
            $raw=$body['keys']??null;
            if(!is_string($raw)||strlen($raw)>2_000_000)throw new InvalidArgumentException('Marketplace delivery key batch is invalid.');
            $values=array_values(array_filter(
                array_map('trim',preg_split('/\R/u',$raw)?:[]),
                static fn(string $value):bool=>$value!==''
            ));
            $this->delivery->addKeys($actor,$listingId,$values,$now,$requestId);
            return;
        }

        if($action==='upload'){
            $file=$request->uploads()['file']??null;
            if(!$file instanceof UploadedFile)throw new InvalidArgumentException('Marketplace delivery upload is missing.');
            $filename=$file->clientFilename;
            if(!is_string($filename)||trim($filename)==='')$filename='delivery.bin';
            $asset=$this->delivery->uploadAsset(
                $actor,$listingId,$this->uploads->read($file,$this->quota->maxFileBytes),$filename,$now
            );
            $this->delivery->configureListing(
                $actor,$listingId,MarketplaceDeliveryType::Download,$asset->assetId,$now,$requestId
            );
            return;
        }

        throw new InvalidArgumentException('Unknown marketplace delivery action.');
    }

    private function listingId(Request $request):?EntityId
    {
        $params=$request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS,[]);
        $raw=is_array($params)?($params['listingId']??null):null;
        return is_string($raw)&&preg_match('/^[a-f0-9]{32}$/D',$raw)===1?EntityId::fromString($raw):null;
    }
}
