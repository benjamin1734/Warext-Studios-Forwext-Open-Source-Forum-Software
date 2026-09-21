<?php

declare(strict_types=1);

namespace Forwext\App\Web\Advertising;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Advertising\AdvertisingDevice;
use Forwext\Core\Advertising\AdvertisingService;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Http\Cookie\ResponseCookie;
use Forwext\Core\Http\Cookie\SameSite;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Routing\Router;
use InvalidArgumentException;

final readonly class AdvertisingClickHandler implements RequestHandlerInterface
{
    private const COOKIE='forwext_ad_viewer';

    public function __construct(
        private AdvertisingService $advertising,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
        private bool $secureCookie=true,
    ){}

    public function handle(Request $request):Response
    {
        $params=$request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS,[]);
        $raw=is_array($params)?($params['campaignId']??null):null;
        if(!is_string($raw)||preg_match('/^[a-f0-9]{32}$/D',$raw)!==1){
            return Response::text('Not Found',404)->withHeader('Cache-Control','no-store');
        }

        $viewer=$this->viewers->resolve($request);
        $token=$request->cookie(self::COOKIE);
        $setCookie=false;
        if($viewer===null&&(!is_string($token)||preg_match('/^[a-f0-9]{32}$/D',$token)!==1)){
            $token=bin2hex(random_bytes(16));
            $setCookie=true;
        }

        try{
            $destination=$this->advertising->trackClick(
                EntityId::fromString($raw),
                $this->advertising->viewerHash($viewer,$viewer===null?$token:null),
                self::device($request),
                new DateTimeImmutable('now',new DateTimeZone('UTC'))
            );
        }catch(InvalidArgumentException){
            return Response::text('Not Found',404)->withHeader('Cache-Control','no-store');
        }

        if(str_starts_with($destination,'/'))$destination=$this->basePath->prepend($destination);
        $response=Response::redirect($destination,302)
            ->withHeader('Cache-Control','no-store')
            ->withHeader('Referrer-Policy','no-referrer')
            ->withHeader('X-Robots-Tag','noindex,nofollow');
        if(!$setCookie||$token===null)return $response;

        return $response->withCookie(new ResponseCookie(
            self::COOKIE,$token,null,31536000,$this->basePath->value()===''?'/':$this->basePath->value(),
            null,$this->secureCookie,true,SameSite::Lax
        ));
    }

    private static function device(Request $request):AdvertisingDevice
    {
        $ua=$request->headers()->first('user-agent')??'';
        return preg_match('/Mobile|Android|iPhone|iPod|iPad|IEMobile|Opera Mini/i',$ua)===1
            ?AdvertisingDevice::Mobile
            :AdvertisingDevice::Desktop;
    }
}
