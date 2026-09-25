<?php

declare(strict_types=1);

namespace Forwext\Core\Routing;

use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;

interface RoutingErrorResponder
{
    public function notFound(Request $request, ?string $relativePath): Response;

    /** @param list<string> $allowedMethods */
    public function methodNotAllowed(Request $request, string $relativePath, array $allowedMethods): Response;
}
