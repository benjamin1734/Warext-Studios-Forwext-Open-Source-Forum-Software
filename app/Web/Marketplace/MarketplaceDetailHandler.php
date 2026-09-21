<?php

declare(strict_types=1);

namespace Forwext\App\Web\Marketplace;

use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Marketplace\MarketplaceService;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Routing\Router;
use InvalidArgumentException;

final readonly class MarketplaceDetailHandler implements RequestHandlerInterface
{
    public function __construct(
        private MarketplaceService $marketplace,private MarketplaceExternalSaleService $externalSales,
        private UserRepository $users,private ProfileViewerResolver $viewers,private BasePath $basePath
    ){}
    public function handle(Request $request):Response
    {
        $actor=$this->viewers->resolve($request);$id=$this->id($request);
        if($id===null)return Response::text('Not Found',404);
        try{
            $listing=$this->marketplace->listing($id,$actor);
            $category=$this->marketplace->category($listing->categoryId,$actor);
            $seller=$this->users->find($listing->sellerUserId);
            if($seller===null)return Response::text('Not Found',404);
            $reviews=$this->marketplace->reviews($id,$actor,50,0);
            $authors=[];
            foreach($reviews as $review){
                $user=$this->users->find($review->reviewerUserId);
                if($user!==null)$authors[$review->reviewerUserId->value()]=$user->username()->display();
            }
            $csrf=$request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
            $csrf=is_string($csrf)&&$csrf!==''?$csrf:null;
            $canReview=$actor!==null&&$this->marketplace->canReview($actor,$listing);
            return Response::html(MarketplaceHtml::detail(
                $listing,$category,$seller->username()->display(),
                $this->marketplace->customFieldsForCategory($listing->categoryId,$actor),
                $this->marketplace->promotion($id,$actor),$reviews,$authors,
                $this->marketplace->reviewSummary($id,$actor),$canReview,
                $actor===null?null:$this->marketplace->ownReview($actor,$id),
                $csrf,$actor!==null&&$this->marketplace->canManageListing($actor,$listing),
                $this->externalSales->publicLink($id,$actor)!==null,
                $this->basePath,($request->query()['reviewed']??null)==='1',$actor!==null
            ))->withHeader('Cache-Control',$actor===null?'public, max-age=30':'private, no-store');
        }catch(PermissionDeniedException){
            return Response::text('Forbidden',403)->withHeader('Cache-Control','no-store');
        }catch(InvalidArgumentException){
            return Response::text('Not Found',404)->withHeader('Cache-Control','no-store');
        }
    }
    private function id(Request $request):?EntityId
    {
        $p=$request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS,[]);
        $v=is_array($p)?($p['listingId']??null):null;
        return is_string($v)&&preg_match('/^[a-f0-9]{32}$/D',$v)===1?EntityId::fromString($v):null;
    }
}
