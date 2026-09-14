<?php

declare(strict_types=1);

namespace Forwext\Core\Http\Middleware;

use Closure;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;

final readonly class CallableRequestHandler implements RequestHandlerInterface
{
    /** @param Closure(Request): Response $handler */
    public function __construct(private Closure $handler)
    {
    }

    public function handle(Request $request): Response
    {
        return ($this->handler)($request);
    }
}
