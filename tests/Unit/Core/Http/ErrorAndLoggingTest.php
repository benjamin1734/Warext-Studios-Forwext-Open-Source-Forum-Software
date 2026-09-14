<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Http;

use Forwext\Core\Http\Canonical\CanonicalUrlMiddleware;
use Forwext\Core\Http\Error\ErrorHandlerMiddleware;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Logging\RequestLoggingMiddleware;
use Forwext\Core\Http\Middleware\CallableRequestHandler;
use Forwext\Core\Http\Middleware\RequestIdMiddleware;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Logging\JsonFileLogger;
use Forwext\Core\Logging\LogLevel;
use Forwext\Core\Logging\MemoryStructuredLogger;
use Forwext\Core\Security\Secret\SecretMasker;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ErrorAndLoggingTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/forwext-log-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700, true));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    public function testJsonLoggerMasksRegisteredAndKeyBasedSecrets(): void
    {
        $path = $this->directory . '/forwext.jsonl';
        $logger = new JsonFileLogger($path, new SecretMasker(['known-secret']));
        $logger->log(LogLevel::Info, 'token known-secret failed', [
            'client_secret' => 'another-secret',
            'safe' => 'visible',
        ]);

        $raw = file_get_contents($path);
        self::assertIsString($raw);
        self::assertStringNotContainsString('known-secret', $raw);
        self::assertStringNotContainsString('another-secret', $raw);
        self::assertStringContainsString('[REDACTED]', $raw);
        self::assertStringContainsString('visible', $raw);
    }

    public function testProductionErrorResponseDoesNotLeakExceptionOrSecret(): void
    {
        $logger = new MemoryStructuredLogger();
        $masker = new SecretMasker(['api-secret']);
        $middleware = new ErrorHandlerMiddleware($logger, $masker, debug: false);
        $terminal = new CallableRequestHandler(static function (Request $_request): Response {
            throw new RuntimeException('database failed with api-secret');
        });
        $request = (new Request(HttpMethod::Get, '/'))
            ->withAttribute(RequestIdMiddleware::ATTRIBUTE, 'request-12345678');

        $response = $middleware->process($request, $terminal);

        self::assertSame(500, $response->status());
        self::assertStringNotContainsString('database failed', $response->body());
        self::assertStringNotContainsString('api-secret', $response->body());
        self::assertSame('request-12345678', $response->headers()->first(RequestIdMiddleware::HEADER));
        self::assertCount(1, $logger->records);
        self::assertSame('database failed with [REDACTED]', $logger->records[0]['context']['exception_message']);
    }

    public function testDebugErrorStillMasksRegisteredSecret(): void
    {
        $middleware = new ErrorHandlerMiddleware(
            new MemoryStructuredLogger(),
            new SecretMasker(['debug-secret']),
            debug: true,
        );
        $terminal = new CallableRequestHandler(static function (Request $_request): Response {
            throw new RuntimeException('failure debug-secret');
        });

        $response = $middleware->process(new Request(HttpMethod::Get, '/'), $terminal);

        self::assertStringContainsString('[REDACTED]', $response->body());
        self::assertStringNotContainsString('debug-secret', $response->body());
    }

    public function testRequestLoggerCorrelatesWithoutLoggingQueryString(): void
    {
        $logger = new MemoryStructuredLogger();
        $middleware = new RequestLoggingMiddleware($logger);
        $terminal = new CallableRequestHandler(static fn (Request $_request): Response => Response::text('ok', 201));
        $request = (new Request(HttpMethod::Post, '/login?token=must-not-be-logged'))
            ->withAttribute(RequestIdMiddleware::ATTRIBUTE, 'request-abcdefgh')
            ->withAttribute(CanonicalUrlMiddleware::ATTRIBUTE_CLIENT_IP, '203.0.113.7');

        $response = $middleware->process($request, $terminal);

        self::assertSame(201, $response->status());
        self::assertCount(1, $logger->records);
        self::assertSame('/login', $logger->records[0]['context']['path']);
        self::assertSame('request-abcdefgh', $logger->records[0]['context']['request_id']);
        self::assertStringNotContainsString('must-not-be-logged', json_encode($logger->records, JSON_THROW_ON_ERROR));
    }
}
