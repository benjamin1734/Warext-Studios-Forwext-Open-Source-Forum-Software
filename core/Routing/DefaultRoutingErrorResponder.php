<?php

declare(strict_types=1);

namespace Forwext\Core\Routing;

use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;

final class DefaultRoutingErrorResponder implements RoutingErrorResponder
{
    public function notFound(Request $request, ?string $relativePath): Response
    {
        return Response::text('Not Found', 404);
    }

    public function methodNotAllowed(Request $request, string $relativePath, array $allowedMethods): Response
    {
        return Response::text('Method Not Allowed', 405)
            ->withHeader('Allow', implode(', ', $allowedMethods));
    }
}
