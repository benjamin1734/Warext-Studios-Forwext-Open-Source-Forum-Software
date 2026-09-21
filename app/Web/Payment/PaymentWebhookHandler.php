<?php

declare(strict_types=1);

namespace Forwext\App\Web\Payment;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Payment\PaymentService;
use Forwext\Core\Payment\PaymentWebhookRequest;
use Forwext\Core\Payment\PaymentWebhookVerificationException;
use Forwext\Core\Routing\Router;
use InvalidArgumentException;

final readonly class PaymentWebhookHandler implements RequestHandlerInterface
{
    public function __construct(private PaymentService $payments){}

    public function handle(Request $request):Response
    {
        $params=$request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS,[]);
        $provider=is_array($params)?($params['providerKey']??null):null;
        if(!is_string($provider)||preg_match('/^[a-z][a-z0-9._-]{1,63}$/D',$provider)!==1){
            return Response::text('Not Found',404)->withHeader('Cache-Control','no-store');
        }
        if(!in_array($provider,$this->payments->providerKeys(),true)){
            return Response::text('Not Found',404)->withHeader('Cache-Control','no-store');
        }

        try{
            $this->payments->handleWebhook($provider,new PaymentWebhookRequest(
                $request->rawBody(),$request->headers()->all(),
                new DateTimeImmutable('now',new DateTimeZone('UTC'))
            ));
            return new Response('',204)
                ->withHeader('Cache-Control','no-store')
                ->withHeader('X-Robots-Tag','noindex,nofollow');
        }catch(PaymentWebhookVerificationException|InvalidArgumentException){
            return Response::text('Invalid webhook.',400)
                ->withHeader('Cache-Control','no-store')
                ->withHeader('X-Robots-Tag','noindex,nofollow');
        }
    }
}
