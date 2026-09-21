<?php

declare(strict_types=1);

namespace Forwext\App\Web\Marketplace;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Marketplace\MarketplaceExternalSaleService;
use Forwext\Core\Routing\Router;
use InvalidArgumentException;

final readonly class MarketplaceExternalSaleRedirectHandler implements RequestHandlerInterface
{
    public function __construct(
        private MarketplaceExternalSaleService $externalSales,
        private ProfileViewerResolver $viewers,
    ){}

    public function handle(Request $request):Response
    {
        $listingId=$this->id($request);
        if($listingId===null)return Response::text('Not Found',404)->withHeader('Cache-Control','no-store');
        try{
            $target=$this->externalSales->redirectTarget(
                $listingId,$this->viewers->resolve($request),new DateTimeImmutable('now',new DateTimeZone('UTC'))
            );
            return Response::redirect($target,303)
                ->withHeader('Cache-Control','no-store')
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
