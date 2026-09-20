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
use Forwext\Core\Marketplace\MarketplaceService;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Routing\Router;
use InvalidArgumentException;

final readonly class MarketplaceReviewHandler implements RequestHandlerInterface
{
    public function __construct(private MarketplaceService $marketplace,private ProfileViewerResolver $viewers,private BasePath $basePath){}
    public function handle(Request $request):Response
    {
        $actor=$this->viewers->resolve($request);
        if($actor===null)return Response::text('Authentication required.',401)->withHeader('Cache-Control','no-store');
        $params=$request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS,[]);
        $raw=is_array($params)?($params['listingId']??null):null;
        if(!is_string($raw)||preg_match('/^[a-f0-9]{32}$/D',$raw)!==1)return Response::text('Not Found',404);
        $body=$request->parsedBody();$rating=filter_var($body['rating']??null,FILTER_VALIDATE_INT);$text=$body['body']??null;
        if(!is_int($rating)||$rating<1||$rating>5||!is_string($text))return Response::text('Bad Request',400);
        try{
            $this->marketplace->saveReview($actor,EntityId::fromString($raw),$rating,$text,new DateTimeImmutable('now',new DateTimeZone('UTC')));
            return Response::text('',303)->withHeader('Location',$this->basePath->prepend('/marketplace/listings/'.$raw.'?reviewed=1'))->withHeader('Cache-Control','no-store');
        }catch(PermissionDeniedException){return Response::text('Forbidden',403)->withHeader('Cache-Control','no-store');}
        catch(InvalidArgumentException){return Response::text('Bad Request',400)->withHeader('Cache-Control','no-store');}
    }
}
