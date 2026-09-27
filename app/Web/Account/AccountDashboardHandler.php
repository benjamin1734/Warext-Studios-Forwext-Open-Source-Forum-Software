<?php

declare(strict_types=1);

namespace Forwext\App\Web\Account;

use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Routing\BasePath;

final readonly class AccountDashboardHandler implements RequestHandlerInterface
{
    public function __construct(
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
    ) {
    }

    public function handle(Request $request): Response
    {
        if ($this->viewers->resolve($request) === null) {
            return Response::text('Authentication required.', 401)->withHeader('Cache-Control', 'no-store');
        }

        return Response::html(AccountDashboardHtml::page($this->basePath))
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Robots-Tag', 'noindex,nofollow');
    }
}
