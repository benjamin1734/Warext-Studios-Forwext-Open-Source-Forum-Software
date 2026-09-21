<?php

declare(strict_types=1);

namespace Forwext\App\Web\Marketplace;

use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Marketplace\MarketplaceExternalSaleService;
use Forwext\Core\Marketplace\MarketplaceService;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Routing\Router;
use InvalidArgumentException;

final readonly class MarketplaceExternalSaleWarningHandler implements RequestHandlerInterface
{
    public function __construct(
        private MarketplaceExternalSaleService $externalSales,
        private MarketplaceService $marketplace,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
    ){}

    public function handle(Request $request):Response
    {
        $actor=$this->viewers->resolve($request);
        $listingId=$this->id($request);
        if($listingId===null)return Response::text('Not Found',404)->withHeader('Cache-Control','no-store');
        try{
            $listing=$this->marketplace->listing($listingId,$actor);
            $link=$this->externalSales->publicLink($listingId,$actor);
            if($link===null)return Response::text('Not Found',404)->withHeader('Cache-Control','no-store');
            $csrf=$request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
            if(!is_string($csrf)||$csrf==='')return Response::text('Internal Server Error',500)->withHeader('Cache-Control','no-store');
            return Response::html(MarketplaceExternalSaleHtml::warning(
                $listing,$link,$this->basePath,$csrf,$actor!==null
            ))->withHeader('Cache-Control','private, no-store')
                ->withHeader('Referrer-Policy','no-referrer')
                ->withHeader('X-Robots-Tag','noindex,nofollow');
        }catch(PermissionDeniedException){
            return Response::text('Forbidden',403)->withHeader('Cache-Control','no-store');
        }catch(InvalidArgumentException){
            return Response::text('Not Found',404)->withHeader('Cache-Control','no-store');
        }
    }

    private function id(Request $request):?EntityId
    {
        $params=$request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS,[]);
        $raw=is_array($params)?($params['listingId']??null):null;
        return is_string($raw)&&preg_match('/^[a-f0-9]{32}$/D',$raw)===1?EntityId::fromString($raw):null;
    }
}
