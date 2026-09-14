<?php

declare(strict_types=1);

namespace Forwext\Core\Http\Error;

use Forwext\Core\Http\Middleware\MiddlewareInterface;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Middleware\RequestIdMiddleware;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Logging\LogLevel;
use Forwext\Core\Logging\StructuredLogger;
use Forwext\Core\Security\Secret\SecretMasker;
use Throwable;

final readonly class ErrorHandlerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private StructuredLogger $logger,
        private SecretMasker $masker,
        private bool $debug = false,
    ) {
    }

    public function process(Request $request, RequestHandlerInterface $next): Response
    {
        try {
            return $next->handle($request);
        } catch (Throwable $throwable) {
            $requestId = $request->attribute(RequestIdMiddleware::ATTRIBUTE);
            if (!is_string($requestId) || $requestId === '') {
                $requestId = bin2hex(random_bytes(16));
            }

            $context = [
                'request_id' => $requestId,
                'exception_class' => $throwable::class,
                'exception_message' => $throwable->getMessage(),
                'file' => $throwable->getFile(),
                'line' => $throwable->getLine(),
            ];
            if ($this->debug) {
                $context['trace'] = $throwable->getTraceAsString();
            }

            try {
                $this->logger->log(
                    LogLevel::Error,
                    'Unhandled HTTP exception',
                    $this->masker->maskContext($context),
                );
            } catch (Throwable) {
                error_log('Forwext structured logging failed while handling an exception.');
            }

            $response = $this->debug
                ? $this->debugResponse($request, $throwable, $requestId)
                : $this->productionResponse($request, $requestId);

            return $response
                ->withHeader('Cache-Control', 'no-store')
                ->withHeader(RequestIdMiddleware::HEADER, $requestId);
        }
    }

    private function productionResponse(Request $request, string $requestId): Response
    {
        if ($this->wantsJson($request)) {
            return Response::json([
                'error' => 'internal_server_error',
                'request_id' => $requestId,
            ], 500);
        }

        return Response::text('Internal Server Error. Request ID: ' . $requestId, 500);
    }

    private function debugResponse(Request $request, Throwable $throwable, string $requestId): Response
    {
        $message = $this->masker->maskString($throwable->getMessage());
        $trace = $this->masker->maskString($throwable->getTraceAsString());

        if ($this->wantsJson($request)) {
            return Response::json([
                'error' => 'debug_exception',
                'request_id' => $requestId,
                'exception' => $throwable::class,
                'message' => $message,
                'trace' => $trace,
            ], 500);
        }

        return Response::text(
            sprintf(
                "Unhandled %s: %s\nRequest ID: %s\n%s",
                $throwable::class,
                $message,
                $requestId,
                $trace,
            ),
            500,
        );
    }

    private function wantsJson(Request $request): bool
    {
        $accept = strtolower($request->headers()->line('Accept') ?? '');
        return $request->isJson() || str_contains($accept, 'application/json') || str_contains($accept, '+json');
    }
}
