<?php

declare(strict_types=1);

namespace Forwext\App\Web\Api\V1;

use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Routing\DefaultRoutingErrorResponder;
use Forwext\Core\Routing\RoutingErrorResponder;

final readonly class ApiV1RoutingErrorResponder implements RoutingErrorResponder
{
    public function __construct(private RoutingErrorResponder $fallback = new DefaultRoutingErrorResponder())
    {
    }

    public function notFound(Request $request, ?string $relativePath): Response
    {
        if (!$this->isApiPath($relativePath)) {
            return $this->fallback->notFound($request, $relativePath);
        }

        return ApiV1ErrorResponder::error('not_found', 'API route not found.', 404);
    }

    public function methodNotAllowed(Request $request, string $relativePath, array $allowedMethods): Response
    {
        if (!$this->isApiPath($relativePath)) {
            return $this->fallback->methodNotAllowed($request, $relativePath, $allowedMethods);
        }

        return ApiV1ErrorResponder::error('method_not_allowed', 'HTTP method is not allowed for this API route.', 405)
            ->withHeader('Allow', implode(', ', $allowedMethods));
    }

    private function isApiPath(?string $path): bool
    {
        return $path === '/api/v1' || ($path !== null && str_starts_with($path, '/api/v1/'));
    }
}
