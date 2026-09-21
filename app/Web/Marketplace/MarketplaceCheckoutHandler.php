<?php

declare(strict_types=1);

namespace Forwext\App\Web\Marketplace;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Marketplace\MarketplaceBillingSnapshot;
use Forwext\Core\Marketplace\MarketplacePurchaseService;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;

final readonly class MarketplaceCheckoutHandler implements RequestHandlerInterface
{
    public function __construct(
        private MarketplacePurchaseService $purchases,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
    ){}

    public function handle(Request $request):Response
    {
        $actor=$this->viewers->resolve($request);
        if($actor===null)return Response::text('Authentication required.',401)->withHeader('Cache-Control','no-store');
        try{
            if($request->method()===HttpMethod::Post){
                $body=$request->parsedBody();
                $billing=new MarketplaceBillingSnapshot(
                    self::required($body,'billing_name',160),
                    self::required($body,'billing_email',254),
                    self::required($body,'billing_country',2),
                    self::optional($body,'billing_tax_id',64),
                    self::optional($body,'billing_address',1000),
                );
                $checkoutKey=self::required($body,'checkout_key',32);
                $this->purchases->checkout(
                    $actor,$billing,$checkoutKey,new DateTimeImmutable('now',new DateTimeZone('UTC'))
                );
                return Response::redirect($this->basePath->prepend('/marketplace/orders?created=1'),303)
                    ->withHeader('Cache-Control','no-store');
            }

            $entries=$this->purchases->cart($actor);
            if($entries===[])return Response::redirect($this->basePath->prepend('/marketplace/cart'),303);
            foreach($entries as $entry)if(!$entry->purchasable){
                return Response::redirect($this->basePath->prepend('/marketplace/cart'),303);
            }
            $csrf=$request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
            if(!is_string($csrf)||$csrf==='')return Response::text('Internal Server Error',500)->withHeader('Cache-Control','no-store');
            return Response::html(MarketplacePurchaseHtml::checkout(
                $entries,$this->basePath,$csrf,bin2hex(random_bytes(16))
            ))->withHeader('Cache-Control','private, no-store')->withHeader('X-Robots-Tag','noindex,nofollow');
        }catch(PermissionDeniedException){
            return Response::text('Forbidden',403)->withHeader('Cache-Control','no-store');
        }catch(InvalidArgumentException){
            return Response::text('Bad Request',400)->withHeader('Cache-Control','no-store');
        }
    }

    /** @param array<string,mixed> $body */
    private static function required(array $body,string $key,int $max):string
    {
        $value=$body[$key]??null;
        if(!is_string($value)||trim($value)===''||strlen(trim($value))>$max){
            throw new InvalidArgumentException('Marketplace checkout field is invalid.');
        }
        return trim($value);
    }

    /** @param array<string,mixed> $body */
    private static function optional(array $body,string $key,int $max):?string
    {
        $value=$body[$key]??null;
        if($value===null||$value==='')return null;
        if(!is_string($value)||strlen($value)>$max)throw new InvalidArgumentException('Marketplace checkout field is invalid.');
        $value=trim($value);
        return $value===''?null:$value;
    }
}
