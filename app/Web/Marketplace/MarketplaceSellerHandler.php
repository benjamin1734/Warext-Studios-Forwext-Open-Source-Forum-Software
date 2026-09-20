<?php

declare(strict_types=1);

namespace Forwext\App\Web\Marketplace;

use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Domain\User\UserStatus;
use Forwext\Core\Domain\User\Username;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Marketplace\MarketplaceListingQuery;
use Forwext\Core\Marketplace\MarketplaceListingSort;
use Forwext\Core\Marketplace\MarketplaceService;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Routing\Router;
use InvalidArgumentException;

final readonly class MarketplaceSellerHandler implements RequestHandlerInterface
{
    public function __construct(
        private MarketplaceService $marketplace,private UserRepository $users,
        private ProfileViewerResolver $viewers,private BasePath $basePath
    ){}
    public function handle(Request $request):Response
    {
        $p=$request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS,[]);$raw=is_array($p)?($p['username']??null):null;
        try{$username=is_string($raw)?Username::fromString($raw):throw new InvalidArgumentException();}
        catch(InvalidArgumentException){return Response::text('Not Found',404);}
        $user=$this->users->findByUsername($username);
        if($user===null||$user->status()!==UserStatus::Active)return Response::text('Not Found',404);
        $actor=$this->viewers->resolve($request);
        $rawPage=$request->query()['page']??null;
        $page=1;
        if($rawPage!==null){
            if(!is_string($rawPage)||preg_match('/^[1-9][0-9]{0,3}$/D',$rawPage)!==1||(int)$rawPage>1000){
                return Response::text('Bad Request',400)->withHeader('X-Robots-Tag','noindex, nofollow');
            }
            $page=(int)$rawPage;
        }
        try{
            $query=new MarketplaceListingQuery(sellerUserId:$user->id(),sort:MarketplaceListingSort::Featured);
            $cards=$this->marketplace->browse($actor,$query,24,($page-1)*24);
            $total=$this->marketplace->browseCount($actor,$query);
            return Response::html(MarketplaceHtml::seller(
                $user->username()->display(),$cards,$page,$total,$this->basePath,$actor!==null
            ));
        }catch(\Forwext\Core\Domain\Access\Permission\PermissionDeniedException){
            return Response::text('Forbidden',403);
        }
    }
}
