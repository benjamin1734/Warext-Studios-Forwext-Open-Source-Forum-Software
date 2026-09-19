<?php

declare(strict_types=1);

namespace Forwext\App\Web\Referral;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Http\Cookie\ResponseCookie;
use Forwext\Core\Http\Cookie\SameSite;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Referral\ReferralService;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Routing\Router;

final readonly class ReferralRedirectHandler implements RequestHandlerInterface
{
    public const COOKIE = '__Host-forwext_referral';

    public function __construct(
        private ReferralService $referrals,
        private BasePath $basePath,
    ) {
    }

    public function handle(Request $request): Response
    {
        $parameters = $request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS, []);
        $code = is_array($parameters) ? ($parameters['code'] ?? null) : null;
        if (!is_string($code)) {
            return Response::text('Not Found', 404);
        }

        $capture = $this->referrals->capture(
            $code,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
        if ($capture === null) {
            return Response::text('Not Found', 404)->withHeader('Cache-Control', 'no-store');
        }

        return Response::redirect($this->basePath->prepend('/'), 302)
            ->withCookie(new ResponseCookie(
                self::COOKIE,
                $capture->code,
                maxAge:$capture->maxAgeSeconds,
                path:'/',
                secure:true,
                httpOnly:true,
                sameSite:SameSite::Lax,
            ))
            ->withHeader('Cache-Control', 'no-store');
    }
}
