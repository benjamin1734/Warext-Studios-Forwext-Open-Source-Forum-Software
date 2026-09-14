<?php

declare(strict_types=1);

namespace Forwext\Core\Http\Middleware;

use Forwext\Core\Http\HttpException;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;

final readonly class MiddlewarePipeline implements RequestHandlerInterface
{
    /**
     * @param list<MiddlewareInterface> $middleware
     */
    public function __construct(
        private array $middleware,
        private RequestHandlerInterface $terminalHandler,
    ) {
        foreach ($middleware as $entry) {
            if (!$entry instanceof MiddlewareInterface) {
                throw new HttpException('Middleware pipeline contains an invalid entry.');
            }
        }
    }

    public function handle(Request $request): Response
    {
        return $this->dispatch($request, 0);
    }

    private function dispatch(Request $request, int $index): Response
    {
        if (!isset($this->middleware[$index])) {
            return $this->terminalHandler->handle($request);
        }

        $middleware = $this->middleware[$index];
        $next = new CallableRequestHandler(
            fn (Request $nextRequest): Response => $this->dispatch($nextRequest, $index + 1),
        );

        return $middleware->process($request, $next);
    }
}
