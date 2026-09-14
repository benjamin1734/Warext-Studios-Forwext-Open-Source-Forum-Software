<?php

declare(strict_types=1);

namespace Forwext\Core\Http\Middleware;

use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;

interface MiddlewareInterface
{
    public function process(Request $request, RequestHandlerInterface $next): Response;
}
