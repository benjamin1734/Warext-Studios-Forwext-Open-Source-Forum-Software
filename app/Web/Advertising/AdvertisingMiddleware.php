<?php

declare(strict_types=1);

namespace Forwext\App\Web\Advertising;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Advertising\AdvertisingDevice;
use Forwext\Core\Advertising\AdvertisingPlacementRegistry;
use Forwext\Core\Advertising\AdvertisingRuntimeContext;
use Forwext\Core\Advertising\AdvertisingService;
use Forwext\Core\Domain\Access\UserAccessAssignmentProvider;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Thread\ThreadRepository;
use Forwext\Core\Http\Cookie\ResponseCookie;
use Forwext\Core\Http\Cookie\SameSite;
use Forwext\Core\Http\Middleware\MiddlewareInterface;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Routing\Router;
use Throwable;

final readonly class AdvertisingMiddleware implements MiddlewareInterface
{
    private const COOKIE='forwext_ad_viewer';

    public function __construct(
        private AdvertisingService $advertising,
        private ProfileViewerResolver $viewers,
        private UserAccessAssignmentProvider $assignments,
        private ThreadRepository $threads,
        private AdvertisingRenderer $renderer,
        private BasePath $basePath,
        private bool $secureCookie=true,
    ){}

    public function process(Request $request,RequestHandlerInterface $next):Response
    {
        $response=$next->handle($request);

        try{
            if(!$this->decoratable($request,$response))return $response;
            $route=$request->attribute(Router::ATTRIBUTE_ROUTE_NAME);
            if(!is_string($route)||$route===''||in_array($route,['advertising.manage','advertising.click'],true)){
                return $response;
            }
            $path=parse_url($request->uri(),PHP_URL_PATH);
            $relative=is_string($path)?$this->basePath->strip($path):null;
            if($relative===null||str_starts_with($relative,'/admin/')){
                return $response;
            }

            $viewer=$this->viewers->resolve($request);
            $anonymousToken=$request->cookie(self::COOKIE);
            $setCookie=false;
            if($viewer===null&&( !is_string($anonymousToken)||preg_match('/^[a-f0-9]{32}$/D',$anonymousToken)!==1)){
                $anonymousToken=bin2hex(random_bytes(16));
                $setCookie=true;
            }

            $groups=[];
            if($viewer!==null){
                $assignment=$this->assignments->find($viewer);
                if($assignment!==null){
                    $groups=array_merge([$assignment->primaryGroupId()],$assignment->secondaryGroupIds());
                }
            }

            $context=new AdvertisingRuntimeContext(
                $route,
                $this->forumId($request),
                $groups,
                self::device($request),
                $this->advertising->viewerHash($viewer,$viewer===null?$anonymousToken:null),
                new DateTimeImmutable('now',new DateTimeZone('UTC'))
            );
            $selected=$this->advertising->select($context,AdvertisingPlacementRegistry::forRoute($route));
            if($selected===[])return $setCookie?$this->withViewerCookie($response,$anonymousToken):$response;

            $body=$response->body();
            $style=AdvertisingRenderer::styles();
            $head=strripos($body,'</head>');
            if($head!==false)$body=substr($body,0,$head).$style.substr($body,$head);

            $top='';
            foreach(['notice.top','content.before','forum.thread.top'] as $placement){
                if(isset($selected[$placement]))$top.=$this->renderer->render($selected[$placement]);
            }
            if($top!==''){
                $marker='<main class="wrap">';
                $position=strpos($body,$marker);
                if($position!==false)$body=substr_replace($body,$marker.$top,$position,strlen($marker));
            }

            if(isset($selected['page.top'])){
                $marker='</header>';
                $position=strpos($body,$marker);
                if($position!==false){
                    $body=substr_replace(
                        $body,$marker.'<div class="wrap fx-page-top">'.$this->renderer->render($selected['page.top']).'</div>',
                        $position,strlen($marker)
                    );
                }
            }

            $bottom='';
            foreach(['forum.thread.bottom','content.after'] as $placement){
                if(isset($selected[$placement]))$bottom.=$this->renderer->render($selected[$placement]);
            }
            if($bottom!==''){
                $position=strripos($body,'</main>');
                if($position!==false)$body=substr($body,0,$position).$bottom.substr($body,$position);
            }

            if(isset($selected['page.bottom'])){
                $position=strripos($body,'</body>');
                if($position!==false){
                    $body=substr($body,0,$position)
                        .'<div class="wrap fx-page-bottom">'.$this->renderer->render($selected['page.bottom']).'</div>'
                        .substr($body,$position);
                }
            }

            $decorated=new Response($body,$response->status(),$response->headers());
            return $setCookie?$this->withViewerCookie($decorated,$anonymousToken):$decorated;
        }catch(Throwable){
            return $response;
        }
    }

    private function decoratable(Request $request,Response $response):bool
    {
        if($request->method()->value!=='GET'||$response->status()!==200)return false;
        $type=strtolower($response->headers()->first('content-type')??'');
        return str_starts_with($type,'text/html');
    }

    private function forumId(Request $request):?EntityId
    {
        $params=$request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS,[]);
        if(!is_array($params))return null;
        foreach(['forumId','forumNodeId','nodeId'] as $key){
            $raw=$params[$key]??null;
            if(is_string($raw)&&$raw!=='')return EntityId::fromString($raw);
        }
        $thread=$params['threadId']??null;
        if(is_string($thread)&&$thread!==''){
            return $this->threads->find(EntityId::fromString($thread))?->forumNodeId();
        }
        return null;
    }

    private static function device(Request $request):AdvertisingDevice
    {
        $ua=$request->headers()->first('user-agent')??'';
        return preg_match('/Mobile|Android|iPhone|iPod|iPad|IEMobile|Opera Mini/i',$ua)===1
            ?AdvertisingDevice::Mobile
            :AdvertisingDevice::Desktop;
    }

    private function withViewerCookie(Response $response,?string $token):Response
    {
        if($token===null)return $response;
        return $response->withCookie(new ResponseCookie(
            self::COOKIE,$token,null,31536000,$this->basePath->value()===''?'/':$this->basePath->value(),
            null,$this->secureCookie,true,SameSite::Lax
        ));
    }
}
