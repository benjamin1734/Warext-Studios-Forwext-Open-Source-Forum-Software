<?php

declare(strict_types=1);

namespace Forwext\App\Web\Api\V1;

use Forwext\Core\Api\V1\ApiV1EndpointRegistry;
use Forwext\Core\Api\V1\PublicApiV1ReadRepository;
use Forwext\Core\Api\V1\PublicApiV1Service;
use Forwext\Core\Routing\PathTemplate;
use Forwext\Core\Routing\Route;
use Forwext\Core\Routing\RouteCollection;

final class ApiV1RouteRegistrar
{
    public static function register(RouteCollection $routes, PublicApiV1ReadRepository $reads): void
    {
        $registry = ApiV1EndpointRegistry::coreDefaults();
        $service = new PublicApiV1Service($reads, $registry);

        foreach ($registry->all() as $endpoint) {
            $routes->add(new Route(
                $endpoint->routeName,
                $endpoint->methods,
                new PathTemplate($endpoint->path, $endpoint->requirements),
                new ApiV1Handler($service, $endpoint),
            ));
        }
    }
}
