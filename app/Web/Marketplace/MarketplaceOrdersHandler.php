<?php

declare(strict_types=1);

namespace Forwext\App\Web\Marketplace;

use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Marketplace\MarketplacePurchaseService;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;

final readonly class MarketplaceOrdersHandler implements RequestHandlerInterface
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
        try{
            return Response::html(MarketplacePurchaseHtml::orders(
                $this->purchases->orders($actor),$actor,$this->basePath,($request->query()['created']??null)==='1'
            ))->withHeader('Cache-Control','private, no-store')->withHeader('X-Robots-Tag','noindex,nofollow');
        }catch(PermissionDeniedException){
            return Response::text('Forbidden',403)->withHeader('Cache-Control','no-store');
        }catch(InvalidArgumentException){
            return Response::text('Bad Request',400)->withHeader('Cache-Control','no-store');
        }
    }
}
