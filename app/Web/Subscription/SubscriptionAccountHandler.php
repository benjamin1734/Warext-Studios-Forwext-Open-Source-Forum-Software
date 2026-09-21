<?php

declare(strict_types=1);

namespace Forwext\App\Web\Subscription;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Subscription\SubscriptionService;

final readonly class SubscriptionAccountHandler implements RequestHandlerInterface
{
    public function __construct(
        private SubscriptionService $subscriptions,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
    ){}

    public function handle(Request $request):Response
    {
        $actor=$this->viewers->resolve($request);
        if($actor===null)return Response::text('Authentication required.',401)->withHeader('Cache-Control','no-store');

        try{
            $csrf=$request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
            if(!is_string($csrf)||$csrf===''){
                return Response::text('Internal Server Error',500)->withHeader('Cache-Control','no-store');
            }
            $snapshot=$this->subscriptions->accountSnapshot(
                $actor,new DateTimeImmutable('now',new DateTimeZone('UTC'))
            );
            $status=$request->query()['payment']??null;
            if(!is_string($status))$status=null;

            return Response::html(SubscriptionHtml::account(
                $snapshot['plans'],$snapshot['subscriptions'],$snapshot['purchases'],$snapshot['providers'],
                $snapshot['can_purchase'],$this->basePath,$csrf,$status
            ))->withHeader('Cache-Control','private, no-store')
                ->withHeader('X-Robots-Tag','noindex,nofollow');
        }catch(PermissionDeniedException){
            return Response::text('Forbidden',403)->withHeader('Cache-Control','no-store');
        }
    }
}
