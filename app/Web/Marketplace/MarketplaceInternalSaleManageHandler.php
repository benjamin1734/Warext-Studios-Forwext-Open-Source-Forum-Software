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
use Forwext\Core\Marketplace\MarketplacePurchaseService;
use Forwext\Core\Marketplace\MarketplaceService;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Routing\Router;
use InvalidArgumentException;

final readonly class MarketplaceInternalSaleManageHandler implements RequestHandlerInterface
{
    public function __construct(
        private MarketplacePurchaseService $purchases,
        private MarketplaceService $marketplace,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
    ){}

    public function handle(Request $request):Response
    {
        $actor=$this->viewers->resolve($request);
        if($actor===null)return Response::text('Authentication required.',401)->withHeader('Cache-Control','no-store');
        $listingId=$this->id($request);
        if($listingId===null)return Response::text('Not Found',404)->withHeader('Cache-Control','no-store');

        try{
            if($request->method()===HttpMethod::Post){
                $enabled=($request->parsedBody()['enabled']??null)==='1';
                $this->purchases->setInternalSale(
                    $actor,$listingId,$enabled,new DateTimeImmutable('now',new DateTimeZone('UTC')),
                    HttpAuditRequestId::fromRequest($request)
                );
                return Response::redirect(
                    $this->basePath->prepend('/marketplace/manage/internal/'.$listingId->value().'?updated=1'),303
                )->withHeader('Cache-Control','no-store');
            }

            $listing=$this->marketplace->managementListing($actor,$listingId);
            $enabled=$this->purchases->managementInternalSaleEnabled($actor,$listingId);
            $csrf=$request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
            if(!is_string($csrf)||$csrf==='')return Response::text('Internal Server Error',500)->withHeader('Cache-Control','no-store');
            return Response::html(MarketplacePurchaseHtml::internalSale(
                $listing,$enabled,$this->basePath,$csrf,($request->query()['updated']??null)==='1'
            ))->withHeader('Cache-Control','private, no-store')->withHeader('X-Robots-Tag','noindex,nofollow');
        }catch(PermissionDeniedException){
            return Response::text('Forbidden',403)->withHeader('Cache-Control','no-store');
        }catch(InvalidArgumentException){
            return Response::text('Bad Request',400)->withHeader('Cache-Control','no-store');
        }
    }

    private function id(Request $request):?EntityId
    {
        $params=$request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS,[]);
        $raw=is_array($params)?($params['listingId']??null):null;
        return is_string($raw)&&preg_match('/^[a-f0-9]{32}$/D',$raw)===1?EntityId::fromString($raw):null;
    }
}
