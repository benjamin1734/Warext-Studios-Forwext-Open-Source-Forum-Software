<?php

declare(strict_types=1);

namespace Forwext\App\Web\Payment;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Payment\PaymentAttemptState;
use Forwext\Core\Payment\PaymentProviderException;
use Forwext\Core\Payment\PaymentService;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Routing\Router;
use InvalidArgumentException;

final readonly class MarketplaceOrderPaymentHandler implements RequestHandlerInterface
{
    public function __construct(
        private PaymentService $payments,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
    ){}

    public function handle(Request $request):Response
    {
        $actor=$this->viewers->resolve($request);
        if($actor===null)return Response::text('Authentication required.',401)->withHeader('Cache-Control','no-store');
        $orderId=$this->orderId($request);
        if($orderId===null)return Response::text('Not Found',404)->withHeader('Cache-Control','no-store');

        try{
            $body=$request->parsedBody();
            $provider=self::required($body,'provider_key',64);
            $idempotency=self::required($body,'idempotency_key',32);
            $path=$this->basePath->prepend('/marketplace/orders/'.$orderId->value());
            $attempt=$this->payments->initiate(
                $actor,$orderId,$provider,$idempotency,$path,$path,
                new DateTimeImmutable('now',new DateTimeZone('UTC'))
            );

            if($attempt->state===PaymentAttemptState::RequiresAction&&$attempt->checkoutUrl!==null){
                return Response::redirect($attempt->checkoutUrl,303)
                    ->withHeader('Cache-Control','no-store')
                    ->withHeader('Referrer-Policy','no-referrer')
                    ->withHeader('X-Robots-Tag','noindex,nofollow');
            }

            return Response::redirect(
                $path.'?payment='.rawurlencode($attempt->state->value),303
            )->withHeader('Cache-Control','no-store');
        }catch(PermissionDeniedException){
            return Response::text('Forbidden',403)->withHeader('Cache-Control','no-store');
        }catch(PaymentProviderException){
            return Response::text('Payment provider unavailable.',502)->withHeader('Cache-Control','no-store');
        }catch(InvalidArgumentException){
            return Response::text('Bad Request',400)->withHeader('Cache-Control','no-store');
        }
    }

    private function orderId(Request $request):?EntityId
    {
        $params=$request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS,[]);
        $raw=is_array($params)?($params['orderId']??null):null;
        return is_string($raw)&&preg_match('/^[a-f0-9]{32}$/D',$raw)===1?EntityId::fromString($raw):null;
    }

    /** @param array<string,mixed> $body */
    private static function required(array $body,string $key,int $max):string
    {
        $value=$body[$key]??null;
        if(!is_string($value)||trim($value)===''||strlen(trim($value))>$max){
            throw new InvalidArgumentException('Payment request field is invalid.');
        }
        return trim($value);
    }
}
