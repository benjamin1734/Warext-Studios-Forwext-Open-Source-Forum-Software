<?php

declare(strict_types=1);

namespace Forwext\App\Web\Payment;

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
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Payment\PaymentProviderException;
use Forwext\Core\Payment\PaymentService;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;

final readonly class PaymentManageHandler implements RequestHandlerInterface
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

        try{
            if($request->method()===HttpMethod::Post){
                $this->mutate($actor,$request);
                return Response::redirect($this->basePath->prepend('/admin/payments?updated=1'),303)
                    ->withHeader('Cache-Control','no-store');
            }

            $csrf=$request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
            if(!is_string($csrf)||$csrf==='')return Response::text('Internal Server Error',500)->withHeader('Cache-Control','no-store');
            return Response::html(PaymentHtml::manage(
                $this->payments->managementSnapshot($actor,100),$this->basePath,$csrf,
                ($request->query()['updated']??null)==='1'
            ))->withHeader('Cache-Control','private, no-store')
                ->withHeader('X-Robots-Tag','noindex,nofollow');
        }catch(PermissionDeniedException){
            return Response::text('Forbidden',403)->withHeader('Cache-Control','no-store');
        }catch(PaymentProviderException){
            return Response::text('Payment provider unavailable.',502)->withHeader('Cache-Control','no-store');
        }catch(InvalidArgumentException){
            return Response::text('Bad Request',400)->withHeader('Cache-Control','no-store');
        }
    }

    private function mutate(EntityId $actor,Request $request):void
    {
        $body=$request->parsedBody();
        $action=$body['action']??null;
        if(!is_string($action))throw new InvalidArgumentException('Payment action is missing.');
        $attemptId=self::id($body['attempt_id']??null);
        $idempotency=self::idempotency($body['idempotency_key']??null);
        $at=new DateTimeImmutable('now',new DateTimeZone('UTC'));

        if($action==='refund'){
            $this->payments->refund($actor,$attemptId,$idempotency,$at,HttpAuditRequestId::fromRequest($request));
            return;
        }
        if($action==='cancel'){
            $this->payments->cancel($actor,$attemptId,$idempotency,$at,HttpAuditRequestId::fromRequest($request));
            return;
        }
        throw new InvalidArgumentException('Unknown payment action.');
    }

    private static function id(mixed $value):EntityId
    {
        if(!is_string($value)||preg_match('/^[a-f0-9]{32}$/D',$value)!==1){
            throw new InvalidArgumentException('Payment attempt id is invalid.');
        }
        return EntityId::fromString($value);
    }

    private static function idempotency(mixed $value):string
    {
        if(!is_string($value)||preg_match('/^[a-f0-9]{32}$/D',$value)!==1){
            throw new InvalidArgumentException('Payment idempotency key is invalid.');
        }
        return $value;
    }
}
