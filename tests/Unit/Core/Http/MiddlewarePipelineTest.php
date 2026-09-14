<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Http;

use Forwext\Core\Http\HeaderBag;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\CallableRequestHandler;
use Forwext\Core\Http\Middleware\MiddlewareInterface;
use Forwext\Core\Http\Middleware\MiddlewarePipeline;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Middleware\RequestIdMiddleware;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use PHPUnit\Framework\TestCase;

final class MiddlewarePipelineTest extends TestCase
{
    public function testMiddlewareOrderIsDeterministicAndPipelineIsReusable(): void
    {
        $events = [];
        $first = new RecordingMiddleware('first', $events);
        $second = new RecordingMiddleware('second', $events);
        $terminal = new CallableRequestHandler(static fn (Request $request): Response => Response::text($request->uri()));
        $pipeline = new MiddlewarePipeline([$first, $second], $terminal);

        self::assertSame('/one', $pipeline->handle(new Request(HttpMethod::Get, '/one'))->body());
        self::assertSame('/two', $pipeline->handle(new Request(HttpMethod::Get, '/two'))->body());
        self::assertSame([
            'before:first', 'before:second', 'after:second', 'after:first',
            'before:first', 'before:second', 'after:second', 'after:first',
        ], $events);
    }

    public function testValidIncomingRequestIdIsCorrelatedToRequestAndResponse(): void
    {
        $terminal = new CallableRequestHandler(static function (Request $request): Response {
            return Response::text((string) $request->attribute(RequestIdMiddleware::ATTRIBUTE));
        });
        $pipeline = new MiddlewarePipeline([new RequestIdMiddleware()], $terminal);
        $request = new Request(
            HttpMethod::Get,
            '/',
            new HeaderBag([RequestIdMiddleware::HEADER => 'client-request-1234']),
        );

        $response = $pipeline->handle($request);

        self::assertSame('client-request-1234', $response->body());
        self::assertSame('client-request-1234', $response->headers()->first(RequestIdMiddleware::HEADER));
    }

    public function testUnsafeIncomingRequestIdIsReplacedWithGeneratedId(): void
    {
        $terminal = new CallableRequestHandler(static function (Request $request): Response {
            return Response::text((string) $request->attribute(RequestIdMiddleware::ATTRIBUTE));
        });
        $pipeline = new MiddlewarePipeline([new RequestIdMiddleware()], $terminal);
        $request = new Request(
            HttpMethod::Get,
            '/',
            new HeaderBag([RequestIdMiddleware::HEADER => 'bad id']),
        );

        $response = $pipeline->handle($request);
        $generated = $response->body();

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $generated);
        self::assertSame($generated, $response->headers()->first(RequestIdMiddleware::HEADER));
    }
}

final class RecordingMiddleware implements MiddlewareInterface
{
    /** @param list<string> $events */
    public function __construct(
        private readonly string $name,
        private array &$events,
    ) {
    }

    public function process(Request $request, RequestHandlerInterface $next): Response
    {
        $this->events[] = 'before:' . $this->name;
        $response = $next->handle($request);
        $this->events[] = 'after:' . $this->name;
        return $response;
    }
}
