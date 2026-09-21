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
use Forwext\Core\Marketplace\MarketplacePurchaseService;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Routing\Router;
use InvalidArgumentException;

final readonly class MarketplaceCartItemHandler implements RequestHandlerInterface
{
    public function __construct(
        private MarketplacePurchaseService $purchases,
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
            $action=$request->parsedBody()['action']??'add';
            if(!is_string($action))throw new InvalidArgumentException('Marketplace cart action is invalid.');
            if($action==='add'){
                $this->purchases->addToCart($actor,$listingId,new DateTimeImmutable('now',new DateTimeZone('UTC')));
            }elseif($action==='remove'){
                $this->purchases->removeFromCart($actor,$listingId);
            }else{
                throw new InvalidArgumentException('Marketplace cart action is invalid.');
            }
            return Response::redirect($this->basePath->prepend('/marketplace/cart'),303)->withHeader('Cache-Control','no-store');
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
