<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Http;

use Forwext\Core\Http\Cookie\ResponseCookie;
use Forwext\Core\Http\Cookie\SameSite;
use Forwext\Core\Http\HeaderBag;
use Forwext\Core\Http\HttpException;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use PHPUnit\Framework\TestCase;

final class RequestResponseTest extends TestCase
{
    public function testJsonRequestParsingHonorsContentTypeAndLimit(): void
    {
        $request = new Request(
            HttpMethod::Post,
            '/api/example',
            new HeaderBag(['Content-Type' => 'application/problem+json; charset=utf-8']),
            rawBody: '{"ok":true}',
        );

        self::assertSame(['ok' => true], $request->json());

        $this->expectException(HttpException::class);
        $request->json(2);
    }

    public function testRequestAttributesAreImmutableCopies(): void
    {
        $request = new Request(HttpMethod::Get, '/');
        $copy = $request->withAttribute('request_id', 'abc12345');

        self::assertNull($request->attribute('request_id'));
        self::assertSame('abc12345', $copy->attribute('request_id'));
    }

    public function testJsonResponseSetsContentType(): void
    {
        $response = Response::json(['message' => 'Merhaba']);

        self::assertSame(200, $response->status());
        self::assertSame('application/json; charset=utf-8', $response->headers()->first('content-type'));
        self::assertSame('{"message":"Merhaba"}', $response->body());
    }

    public function testSecureCookieSerializationAndPrefixRules(): void
    {
        $cookie = new ResponseCookie(
            '__Host-session',
            'abc 123',
            maxAge: 3600,
            secure: true,
            httpOnly: true,
            sameSite: SameSite::Lax,
        );

        $response = (new Response())->withCookie($cookie);
        $header = $response->headers()->first('set-cookie');

        self::assertNotNull($header);
        self::assertStringContainsString('__Host-session=abc%20123', $header);
        self::assertStringContainsString('Secure', $header);
        self::assertStringContainsString('HttpOnly', $header);
        self::assertStringContainsString('SameSite=Lax', $header);
    }

    public function testSameSiteNoneWithoutSecureIsRejected(): void
    {
        $this->expectException(HttpException::class);
        new ResponseCookie('session', 'value', secure: false, sameSite: SameSite::None);
    }

    public function testCookieAttributeDelimiterInjectionIsRejected(): void
    {
        try {
            new ResponseCookie('session', 'value', path: '/; SameSite=None');
            self::fail('Cookie path delimiter injection should have been rejected.');
        } catch (HttpException) {
            self::assertTrue(true);
        }

        $this->expectException(HttpException::class);
        new ResponseCookie('session', 'value', domain: 'example.com; Secure');
    }
}
