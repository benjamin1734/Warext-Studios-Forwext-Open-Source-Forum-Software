<?php

declare(strict_types=1);

namespace Forwext\App\Web\Marketplace;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Audit\HttpAuditRequestId;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Marketplace\MarketplaceOrderState;
use Forwext\Core\Marketplace\Delivery\MarketplaceDeliveryService;
use Forwext\Core\Marketplace\MarketplacePaymentState;
use Forwext\Core\Marketplace\MarketplacePurchaseService;
use Forwext\Core\Payment\PaymentAttemptState;
use Forwext\Core\Payment\PaymentService;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Routing\Router;
use InvalidArgumentException;

final readonly class MarketplaceOrderDetailHandler implements RequestHandlerInterface
{
    public function __construct(
        private MarketplacePurchaseService $purchases,
        private MarketplaceDeliveryService $delivery,
        private PaymentService $payments,
        private UserRepository $users,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
    ){}

    public function handle(Request $request):Response
    {
        $actor=$this->viewers->resolve($request);
        if($actor===null)return Response::text('Authentication required.',401)->withHeader('Cache-Control','no-store');
        $orderId=$this->id($request);
        if($orderId===null)return Response::text('Not Found',404)->withHeader('Cache-Control','no-store');

        try{
            if($request->method()===HttpMethod::Post){
                $body=$request->parsedBody();
                $action=$body['action']??null;
                if($action!=='cancel')throw new InvalidArgumentException('Marketplace order action is invalid.');

                $snapshot=$this->purchases->order($actor,$orderId);
                $order=$snapshot['order'];
                $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
                $auditRequestId=HttpAuditRequestId::fromRequest($request);

                if($order->buyerUserId->equals($actor)){
                    $cancelKey=$body['payment_cancel_key']??null;
                    if(!is_string($cancelKey)||preg_match('/^[a-f0-9]{32}$/D',$cancelKey)!==1){
                        throw new InvalidArgumentException('Payment cancellation idempotency key is invalid.');
                    }
                    $this->payments->cancelForOrderBuyer(
                        $actor,$orderId,$cancelKey,$now,$auditRequestId
                    );
                }

                $this->purchases->cancelPending($actor,$orderId,$now,$auditRequestId);
                return Response::redirect(
                    $this->basePath->prepend('/marketplace/orders/'.$orderId->value().'?cancelled=1'),303
                )->withHeader('Cache-Control','no-store');
            }

            $snapshot=$this->purchases->order($actor,$orderId);
            $order=$snapshot['order'];
            $buyer=$this->users->find($order->buyerUserId);
            $seller=$this->users->find($order->sellerUserId);
            $buyerName=$buyer?->username()->display()??'Silinmiş kullanıcı';
            $sellerName=$seller?->username()->display()??'Silinmiş kullanıcı';
            $isBuyer=$order->buyerUserId->equals($actor);
            $isManager=$this->purchases->canManageOrders($actor);
            $canCancel=$order->state===MarketplaceOrderState::Pending&&(
                ($isBuyer&&in_array(
                    $order->paymentState,
                    [
                        MarketplacePaymentState::Pending,
                        MarketplacePaymentState::Authorized,
                        MarketplacePaymentState::Failed,
                        MarketplacePaymentState::Cancelled,
                    ],
                    true
                ))
                ||(!$isBuyer&&$isManager
                    &&in_array(
                        $order->paymentState,
                        [
                            MarketplacePaymentState::Pending,
                            MarketplacePaymentState::Failed,
                            MarketplacePaymentState::Cancelled,
                        ],
                        true
                    )
                    &&!$this->payments->hasActiveAttempt($order)
                )
            );
            $csrf=$request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
            if(!is_string($csrf)||$csrf==='')return Response::text('Internal Server Error',500)->withHeader('Cache-Control','no-store');
            $canPay=$this->payments->canInitiate($actor,$order);
            $paymentStatus=null;
            $rawPayment=$request->query()['payment']??null;
            if(is_string($rawPayment)&&PaymentAttemptState::tryFrom($rawPayment)!==null)$paymentStatus=$rawPayment;
            $deliverySnapshot=$this->delivery->orderSnapshot($actor,$orderId);
            $history=$this->purchases->orderHistory($actor,$orderId);
            return Response::html(MarketplacePurchaseHtml::order(
                $order,$snapshot['items'],$deliverySnapshot['deliveries'],$history,$actor,$buyerName,$sellerName,$canCancel,
                $canPay?$this->payments->providerKeys():[],
                $canPay?bin2hex(random_bytes(16)):null,
                $canCancel?bin2hex(random_bytes(16)):null,
                $this->basePath,$csrf,
                ($request->query()['cancelled']??null)==='1',$paymentStatus
            ))->withHeader('Cache-Control','private, no-store')->withHeader('X-Robots-Tag','noindex,nofollow');
        }catch(PermissionDeniedException){
            return Response::text('Forbidden',403)->withHeader('Cache-Control','no-store');
        }catch(InvalidArgumentException){
            return Response::text('Not Found',404)->withHeader('Cache-Control','no-store');
        }
    }

    private function id(Request $request):?EntityId
    {
        $params=$request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS,[]);
        $raw=is_array($params)?($params['orderId']??null):null;
        return is_string($raw)&&preg_match('/^[a-f0-9]{32}$/D',$raw)===1?EntityId::fromString($raw):null;
    }
}
