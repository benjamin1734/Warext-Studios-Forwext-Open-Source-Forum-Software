<?php

declare(strict_types=1);

namespace Forwext\App\Web\Marketplace;

use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Marketplace\MarketplaceListingQuery;
use Forwext\Core\Marketplace\MarketplaceListingSort;
use Forwext\Core\Marketplace\MarketplaceService;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;

final readonly class MarketplaceBrowseHandler implements RequestHandlerInterface
{
    private const PAGE_SIZE=24;
    public function __construct(private MarketplaceService $marketplace,private ProfileViewerResolver $viewers,private BasePath $basePath){}
    public function handle(Request $request):Response
    {
        $actor=$this->viewers->resolve($request);
        try{
            $categories=$this->marketplace->categories($actor);
            $categorySlug=self::scalar($request->query(),'category',96);
            $categoryId=null;
            if($categorySlug!==null&&$categorySlug!==''){
                foreach($categories as $category)if($category->slug===$categorySlug){$categoryId=$category->categoryId;break;}
                if($categoryId===null)throw new InvalidArgumentException('Marketplace category filter is invalid.');
            }
            $sort=MarketplaceListingSort::tryFrom(self::scalar($request->query(),'sort',16)??'featured')
                ?? throw new InvalidArgumentException('Marketplace sort is invalid.');
            $view=self::scalar($request->query(),'view',8)??'grid';
            if(!in_array($view,['grid','list'],true))throw new InvalidArgumentException('Marketplace view is invalid.');
            $query=new MarketplaceListingQuery(
                self::nullable(self::scalar($request->query(),'q',200)),
                $categoryId,
                null,
                ($currency=self::nullable(self::scalar($request->query(),'currency',3)))===null?null:strtoupper($currency),
                self::money(self::nullable(self::scalar($request->query(),'min',32))),
                self::money(self::nullable(self::scalar($request->query(),'max',32))),
                ($tag=self::nullable(self::scalar($request->query(),'tag',64)))===null?null:strtolower($tag),
                $sort,
                self::scalar($request->query(),'featured',1)==='1',
                false,
            );
            $page=self::page($request->query());
            $cards=$this->marketplace->browse($actor,$query,self::PAGE_SIZE,($page-1)*self::PAGE_SIZE);
            $total=$this->marketplace->browseCount($actor,$query);
            return Response::html(MarketplaceHtml::browse(
                $cards,$categories,$query,$view,$page,$total,$this->basePath,$actor!==null
            ));
        }catch(PermissionDeniedException){
            return Response::text('Forbidden',403)->withHeader('Cache-Control','private, no-store');
        }catch(InvalidArgumentException){
            return Response::text('Bad Request',400)->withHeader('X-Robots-Tag','noindex, nofollow');
        }
    }

    /** @param array<string,mixed> $query */
    private static function scalar(array $query,string $key,int $max):?string
    {
        $v=$query[$key]??null;if($v===null)return null;
        if(!is_string($v)||strlen($v)>$max||preg_match('/[\x00-\x1F\x7F]/',$v)===1)throw new InvalidArgumentException('Marketplace query parameter is invalid.');
        return trim($v);
    }
    private static function nullable(?string $v):?string{return $v===null||$v===''?null:$v;}
    private static function money(?string $v):?int
    {
        if($v===null)return null;
        if(preg_match('/^(\d{1,13})(?:\.(\d{1,2}))?$/D',$v,$m)!==1)throw new InvalidArgumentException('Marketplace price filter is invalid.');
        return ((int)$m[1])*100+(int)str_pad($m[2]??'',2,'0');
    }
    /** @param array<string,mixed> $query */
    private static function page(array $query):int
    {
        $v=self::scalar($query,'page',4);if($v===null||$v==='')return 1;
        if(preg_match('/^[1-9][0-9]{0,3}$/D',$v)!==1||(int)$v>1000)throw new InvalidArgumentException('Marketplace page is invalid.');
        return (int)$v;
    }
}
