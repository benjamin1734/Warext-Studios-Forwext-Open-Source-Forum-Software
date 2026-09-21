<?php

declare(strict_types=1);

namespace Forwext\App\Web\Marketplace;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Audit\HttpAuditRequestId;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Marketplace\Delivery\MarketplaceDeliveryService;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Routing\Router;
use InvalidArgumentException;

final readonly class MarketplaceOrderDeliveryHandler implements RequestHandlerInterface
{
    public function __construct(
        private MarketplaceDeliveryService $delivery,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
    ){}

    public function handle(Request $request):Response
    {
        $actor=$this->viewers->resolve($request);
        if($actor===null)return Response::text('Authentication required.',401)->withHeader('Cache-Control','no-store');

        [$orderId,$itemId,$action]=$this->parameters($request);
        if($orderId===null||$itemId===null||$action===null){
            return Response::text('Not Found',404)->withHeader('Cache-Control','no-store');
        }

        try{
            $snapshot=$this->delivery->orderSnapshot($actor,$orderId);
            $record=null;
            foreach($snapshot['deliveries'] as $candidate){
                if($candidate->orderItemId->equals($itemId)&&$candidate->orderId->equals($orderId)){
                    $record=$candidate;
                    break;
                }
            }
            if($record===null)throw new InvalidArgumentException('Marketplace order delivery was not found.');

            $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
            if($action==='download'){
                if($request->method()!==HttpMethod::Get)return $this->method('GET');
                $download=$this->delivery->download($actor,$itemId,$now);
                return (new Response($download->contents,200))
                    ->withHeader('Content-Type',$download->mediaType)
                    ->withHeader('Content-Length',(string)strlen($download->contents))
                    ->withHeader('Content-Disposition',"attachment; filename*=UTF-8''".rawurlencode($download->filename))
                    ->withHeader('X-Content-Type-Options','nosniff')
                    ->withHeader('Cache-Control','private, no-store')
                    ->withHeader('Referrer-Policy','no-referrer');
            }

            if($request->method()!==HttpMethod::Post)return $this->method('POST');

            if($action==='reveal'){
                $value=$this->delivery->reveal($actor,$itemId,$now);
                return Response::html(MarketplaceDeliveryHtml::revealed(
                    $snapshot['order'],$record,$value,$this->basePath
                ))->withHeader('Cache-Control','private, no-store')
                    ->withHeader('Pragma','no-cache')
                    ->withHeader('Referrer-Policy','no-referrer')
                    ->withHeader('X-Robots-Tag','noindex,nofollow');
            }

            if($action==='fulfill'){
                $value=$request->parsedBody()['value']??null;
                if(!is_string($value)||trim($value)===''||strlen($value)>16384){
                    throw new InvalidArgumentException('Marketplace manual fulfillment value is invalid.');
                }
                $this->delivery->fulfillManual(
                    $actor,$itemId,$value,$now,HttpAuditRequestId::fromRequest($request)
                );
                return Response::redirect(
                    $this->basePath->prepend('/marketplace/orders/'.$orderId->value().'?fulfilled=1'),303
                )->withHeader('Cache-Control','no-store');
            }

            throw new InvalidArgumentException('Marketplace delivery action is invalid.');
        }catch(PermissionDeniedException){
            return Response::text('Forbidden',403)->withHeader('Cache-Control','no-store');
        }catch(InvalidArgumentException){
            return Response::text('Not Found',404)->withHeader('Cache-Control','no-store');
        }
    }

    /** @return array{?EntityId,?EntityId,?string} */
    private function parameters(Request $request):array
    {
        $params=$request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS,[]);
        if(!is_array($params))return [null,null,null];
        $order=$params['orderId']??null;$item=$params['itemId']??null;$action=$params['action']??null;
        if(!is_string($order)||preg_match('/^[a-f0-9]{32}$/D',$order)!==1
            ||!is_string($item)||preg_match('/^[a-f0-9]{32}$/D',$item)!==1
            ||!is_string($action)||!in_array($action,['fulfill','reveal','download'],true)
        )return [null,null,null];
        return [EntityId::fromString($order),EntityId::fromString($item),$action];
    }

    private function method(string $allow):Response
    {
        return Response::text('Method Not Allowed',405)->withHeader('Allow',$allow)->withHeader('Cache-Control','no-store');
    }
}
