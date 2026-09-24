<?php

declare(strict_types=1);

namespace Forwext\Core\Module\FirstParty;

use Forwext\Core\Http\Middleware\MiddlewareInterface;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Routing\Router;

final readonly class FirstPartyModuleRouteMiddleware implements MiddlewareInterface
{
    public function __construct(
        private FirstPartyModuleRegistry $registry,
        private FirstPartyModuleRepository $repository,
    ) {
    }

    public function process(Request $request, RequestHandlerInterface $next): Response
    {
        $routeName = $request->attribute(Router::ATTRIBUTE_ROUTE_NAME);
        if (!is_string($routeName)) {
            return $next->handle($request);
        }

        $module = $this->registry->moduleForRoute($routeName);
        if ($module === null) {
            return $next->handle($request);
        }

        return match ($this->repository->state($module->key)->state) {
            FirstPartyModuleState::Enabled => $next->handle($request),
            FirstPartyModuleState::Disabled => Response::text('Service Unavailable', 503)
                ->withHeader('Cache-Control', 'no-store')
                ->withHeader('Retry-After', '3600'),
            FirstPartyModuleState::Uninstalled => Response::text('Not Found', 404)
                ->withHeader('Cache-Control', 'no-store'),
        };
    }
}
