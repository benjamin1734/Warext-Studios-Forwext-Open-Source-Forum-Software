<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web\Api;

use Forwext\App\Web\Api\V1\ApiV1RouteRegistrar;
use DateTimeImmutable;
use Forwext\Core\Api\V1\ApiV1Page;
use Forwext\Core\Api\V1\ApiV1Scope;
use Forwext\Core\Api\V1\PrivateApiV1ReadRepository;
use Forwext\Core\Api\V1\PublicApiV1ReadRepository;
use Forwext\Core\Api\V1\Security\ApiV1AccountPermissionChecker;
use Forwext\Core\Api\V1\Security\ApiV1CredentialRecord;
use Forwext\Core\Api\V1\Security\ApiV1CredentialRepository;
use Forwext\Core\Api\V1\Security\ApiV1CredentialResolver;
use Forwext\Core\Audit\AuditEvent;
use Forwext\Core\Audit\AuditRecorder;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Security\RateLimit\InMemoryRateLimitStore;
use Forwext\Core\Routing\RouteCollection;
use Forwext\Core\Routing\Router;
use PHPUnit\Framework\TestCase;

final class ApiV1PublicSurfaceTest extends TestCase
{
    public function testVersionedRoutesExposeTypedServiceDocumentAndPublicData(): void
    {
        $routes = new RouteCollection();
        self::registerRoutes($routes);
        $router = new Router($routes);

        $root = $router->handle(new Request(HttpMethod::Get, '/api/v1'));
        self::assertSame(200, $root->status());
        $document = json_decode($root->body(), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('v1', $document['data']['version']);
        self::assertContains('users.read', $document['data']['scopes']);
        foreach (['users','forums','threads','posts','conversations','notifications','modules','marketplace','support'] as $resource) {
            self::assertContains($resource, $document['data']['resources']);
        }

        $endpointNames = array_column($document['data']['endpoints'], 'name');
        foreach ([
            'api.v1.conversations.index',
            'api.v1.notifications.index',
            'api.v1.modules.index',
            'api.v1.marketplace.index',
            'api.v1.marketplace.show',
            'api.v1.support.categories',
            'api.v1.support.tickets',
        ] as $name) {
            self::assertContains($name, $endpointNames);
        }

        $userId = str_repeat('1', 32);
        $user = $router->handle(new Request(HttpMethod::Get, '/api/v1/users/' . $userId));
        self::assertSame(200, $user->status());
        self::assertStringContainsString('"username":"PublicUser"', $user->body());
        self::assertStringNotContainsString('email', $user->body());
        self::assertStringNotContainsString('timezone', $user->body());

        $modules = $router->handle(new Request(HttpMethod::Get, '/api/v1/modules'));
        self::assertSame(200, $modules->status());
        self::assertStringContainsString('"key":"marketplace"', $modules->body());

        $listingId = str_repeat('2', 32);
        $listing = $router->handle(new Request(HttpMethod::Get, '/api/v1/marketplace/' . $listingId));
        self::assertSame(200, $listing->status());
        self::assertStringContainsString('"title":"Public listing"', $listing->body());

        $support = $router->handle(new Request(HttpMethod::Get, '/api/v1/support/categories'));
        self::assertSame(200, $support->status());
        self::assertStringContainsString('"key":"general"', $support->body());
    }

    public function testProtectedResourceContractsFailClosedUntilApiAuthenticationIsAttached(): void
    {
        $routes = new RouteCollection();
        self::registerRoutes($routes);
        $router = new Router($routes);

        foreach (['/api/v1/conversations','/api/v1/notifications','/api/v1/support/tickets'] as $path) {
            $response = $router->handle(new Request(HttpMethod::Get, $path));
            self::assertSame(401, $response->status(), $path);
            self::assertStringContainsString('"code":"authentication_required"', $response->body(), $path);
            self::assertSame('no-store', $response->headers()->first('cache-control'), $path);
        }
    }

    public function testPaginationIsBoundedAndMissingResourceUsesJsonError(): void
    {
        $routes = new RouteCollection();
        self::registerRoutes($routes);
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

    private static function registerRoutes(RouteCollection $routes): void
    {
        ApiV1RouteRegistrar::register(
            $routes,
            new ApiV1ReadFixture(),
            new ApiV1PrivateReadFixture(),
            new ApiV1CredentialResolver(new ApiV1CredentialRepositoryFixture()),
            new ApiV1PublicPermissionCheckerFixture(),
            new InMemoryRateLimitStore(),
            new ApiV1AuditRecorderFixture(),
        );
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

    public function modules(int $page, int $perPage): ApiV1Page
    {
        return new ApiV1Page([['key'=>'marketplace','state'=>'enabled']], $page, $perPage, false);
    }

    public function marketplace(int $page, int $perPage): ApiV1Page
    {
        return new ApiV1Page([$this->marketplaceListing(str_repeat('2', 32))], $page, $perPage, false);
    }

    public function marketplaceListing(string $listingId): ?array
    {
        return $listingId === str_repeat('2', 32)
            ? [
                'id'=>$listingId,
                'seller_user_id'=>str_repeat('3', 32),
                'category_id'=>str_repeat('4', 32),
                'slug'=>'public-listing',
                'title'=>'Public listing',
                'description'=>'Public description',
                'price_minor'=>1250,
                'currency'=>'TRY',
                'state'=>'active',
                'created_at'=>'2026-01-01T00:00:00.000000Z',
                'updated_at'=>'2026-01-01T00:00:00.000000Z',
            ]
            : null;
    }

    public function supportCategories(int $page, int $perPage): ApiV1Page
    {
        return new ApiV1Page([[
            'key'=>'general',
            'label'=>'Genel Destek',
            'description'=>'Genel destek talepleri.',
            'default_priority'=>'normal',
            'sort_order'=>10,
        ]], $page, $perPage, false);
    }
}


final class ApiV1PrivateReadFixture implements PrivateApiV1ReadRepository
{
    public function conversations(EntityId $userId, int $page, int $perPage): ApiV1Page
    {
        return new ApiV1Page([], $page, $perPage, false);
    }

    public function notifications(EntityId $userId, int $page, int $perPage): ApiV1Page
    {
        return new ApiV1Page([], $page, $perPage, false);
    }

    public function supportTickets(EntityId $userId, int $page, int $perPage): ApiV1Page
    {
        return new ApiV1Page([], $page, $perPage, false);
    }
}

final class ApiV1CredentialRepositoryFixture implements ApiV1CredentialRepository
{
    public function findBySecretHash(string $secretHash): ?ApiV1CredentialRecord
    {
        return null;
    }

    public function save(ApiV1CredentialRecord $record): void
    {
    }

    public function markUsed(EntityId $credentialId, DateTimeImmutable $at): void
    {
    }

    public function revoke(EntityId $credentialId, EntityId $ownerUserId, DateTimeImmutable $at): void
    {
    }
}

final class ApiV1AuditRecorderFixture implements AuditRecorder
{
    public function append(AuditEvent $event): void
    {
    }

    public function mutate(AuditEvent $event, callable $mutation): mixed
    {
        return $mutation();
    }
}


final class ApiV1PublicPermissionCheckerFixture implements ApiV1AccountPermissionChecker
{
    public function allows(EntityId $userId, ApiV1Scope $scope): bool
    {
        return true;
    }
}
