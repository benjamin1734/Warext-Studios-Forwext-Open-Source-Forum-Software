<?php

declare(strict_types=1);

namespace Forwext\App\Web\Marketplace;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Forum\VerifiedUploadedAttachmentReader;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Attachment\AttachmentOperationException;
use Forwext\Core\Forum\Attachment\AttachmentQuotaPolicy;
use Forwext\Core\Http\HttpException;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Upload\UploadedFile;
use Forwext\Core\Marketplace\MarketplaceMediaService;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Routing\Router;
use InvalidArgumentException;

final readonly class MarketplaceMediaUploadHandler implements RequestHandlerInterface
{
    public function __construct(
        private MarketplaceMediaService $media,
        private ProfileViewerResolver $viewers,
        private VerifiedUploadedAttachmentReader $uploads,
        private AttachmentQuotaPolicy $quota,
        private BasePath $basePath,
    ){}

    public function handle(Request $request):Response
    {
        $actor=$this->viewers->resolve($request);
        if($actor===null)return Response::text('Authentication required.',401)->withHeader('Cache-Control','no-store');
        $params=$request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS,[]);
        $raw=is_array($params)?($params['listingId']??null):null;
        if(!is_string($raw)||preg_match('/^[a-f0-9]{32}$/D',$raw)!==1)return Response::text('Not Found',404);
        $listingId=EntityId::fromString($raw);
        try{
            $body=$request->parsedBody();
            if(($body['action']??null)==='delete'){
                $mediaId=$body['media_id']??null;
                if(!is_string($mediaId)||preg_match('/^[a-f0-9]{32}$/D',$mediaId)!==1)throw new InvalidArgumentException('Marketplace media id is invalid.');
                $this->media->delete($actor,$listingId,EntityId::fromString($mediaId));
            }else{
                $upload=$request->uploads()['file']??null;
                if(!$upload instanceof UploadedFile)throw new InvalidArgumentException('Marketplace media upload is missing.');
                $alt=$body['alt']??'';
                if(!is_string($alt))throw new InvalidArgumentException('Marketplace media alt text is invalid.');
                $this->media->upload(
                    $actor,$listingId,$this->uploads->read($upload,$this->quota->maxFileBytes),trim($alt),
                    new DateTimeImmutable('now',new DateTimeZone('UTC'))
                );
            }
            return Response::redirect(
                $this->basePath->prepend('/marketplace/manage?updated=1&listing='.$listingId->value()),303
            )->withHeader('Cache-Control','no-store');
        }catch(PermissionDeniedException){return Response::text('Forbidden',403)->withHeader('Cache-Control','no-store');}
        catch(HttpException|InvalidArgumentException){return Response::text('Bad Request',400)->withHeader('Cache-Control','no-store');}
        catch(AttachmentOperationException){return Response::text('Media rejected.',422)->withHeader('Cache-Control','no-store');}
    }
}
