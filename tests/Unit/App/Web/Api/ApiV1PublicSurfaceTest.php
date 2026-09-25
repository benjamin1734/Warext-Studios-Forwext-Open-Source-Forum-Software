<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web\Api;

use Forwext\App\Web\Api\V1\ApiV1RouteRegistrar;
use Forwext\Core\Api\V1\ApiV1Page;
use Forwext\Core\Api\V1\PublicApiV1ReadRepository;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Request;
use Forwext\Core\Routing\RouteCollection;
use Forwext\Core\Routing\Router;
use PHPUnit\Framework\TestCase;

final class ApiV1PublicSurfaceTest extends TestCase
{
    public function testVersionedRoutesExposeTypedServiceDocumentAndPublicData(): void
    {
        $routes = new RouteCollection();
        ApiV1RouteRegistrar::register($routes, new ApiV1ReadFixture());
        $router = new Router($routes);

        $root = $router->handle(new Request(HttpMethod::Get, '/api/v1'));
        self::assertSame(200, $root->status());
        $document = json_decode($root->body(), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('v1', $document['data']['version']);
        self::assertContains('users.read', $document['data']['scopes']);
        self::assertContains('marketplace', $document['data']['resources']);

        $userId = str_repeat('1', 32);
        $user = $router->handle(new Request(HttpMethod::Get, '/api/v1/users/' . $userId));
        self::assertSame(200, $user->status());
        self::assertStringContainsString('"username":"PublicUser"', $user->body());
        self::assertStringNotContainsString('email', $user->body());
        self::assertStringNotContainsString('timezone', $user->body());
    }

    public function testPaginationIsBoundedAndMissingResourceUsesJsonError(): void
    {
        $routes = new RouteCollection();
        ApiV1RouteRegistrar::register($routes, new ApiV1ReadFixture());
        $router = new Router($routes);

        $invalid = $router->handle(new Request(
            HttpMethod::Get,
            '/api/v1/forums?per_page=500',
            query:['per_page'=>'500'],
        ));
        self::assertSame(400, $invalid->status());
        self::assertStringContainsString('"code":"invalid_request"', $invalid->body());

        $missing = $router->handle(new Request(
            HttpMethod::Get,
            '/api/v1/posts/' . str_repeat('f', 32),
        ));
        self::assertSame(404, $missing->status());
        self::assertStringContainsString('"code":"not_found"', $missing->body());
        self::assertSame('no-store', $missing->headers()->first('cache-control'));
    }
}

final class ApiV1ReadFixture implements PublicApiV1ReadRepository
{
    public function user(string $userId): ?array
    {
        return $userId === str_repeat('1', 32)
            ? ['id'=>$userId,'username'=>'PublicUser','created_at'=>'2026-01-01T00:00:00.000000Z','updated_at'=>'2026-01-01T00:00:00.000000Z']
            : null;
    }

    public function forums(int $page, int $perPage): ApiV1Page
    {
        return new ApiV1Page([], $page, $perPage, false);
    }

    public function forum(string $forumId): ?array { return null; }
    public function threads(string $forumId, int $page, int $perPage): ?ApiV1Page { return null; }
    public function thread(string $threadId): ?array { return null; }
    public function posts(string $threadId, int $page, int $perPage): ?ApiV1Page { return null; }
    public function post(string $postId): ?array { return null; }
}
