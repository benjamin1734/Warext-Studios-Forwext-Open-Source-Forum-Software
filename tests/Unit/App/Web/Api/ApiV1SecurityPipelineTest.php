<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web\Api;

use DateTimeImmutable;
use Forwext\App\Web\Api\V1\ApiV1RouteRegistrar;
use Forwext\Core\Api\V1\ApiV1Page;
use Forwext\Core\Api\V1\ApiV1Scope;
use Forwext\Core\Api\V1\PrivateApiV1ReadRepository;
use Forwext\Core\Api\V1\PublicApiV1ReadRepository;
use Forwext\Core\Api\V1\Security\ApiV1CredentialRecord;
use Forwext\Core\Api\V1\Security\ApiV1CredentialRepository;
use Forwext\Core\Api\V1\Security\ApiV1CredentialResolver;
use Forwext\Core\Api\V1\Security\ApiV1CredentialService;
use Forwext\Core\Api\V1\Security\ApiV1PrincipalType;
use Forwext\Core\Audit\AuditEvent;
use Forwext\Core\Audit\AuditRecorder;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Http\HeaderBag;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Security\RateLimit\InMemoryRateLimitStore;
use Forwext\Core\Routing\RouteCollection;
use Forwext\Core\Routing\Router;
use PHPUnit\Framework\TestCase;

final class ApiV1SecurityPipelineTest extends TestCase
{
    public function testScopedCredentialUnlocksOnlyItsPrivateResourceAndIsAudited(): void
    {
        $credentials = new ApiSecurityCredentialRepositoryFixture();
        $owner = EntityId::fromString(str_repeat('a', 32));
        $issued = (new ApiV1CredentialService($credentials))->issue(
            $owner,
            ApiV1PrincipalType::PersonalToken,
            'Notifications client',
            [ApiV1Scope::NotificationsRead],
        );
        $audit = new ApiAuditRecorderFixture();
        $router = $this->router($credentials, $audit);

        $notifications = $router->handle(new Request(
            HttpMethod::Get,
            '/api/v1/notifications',
            new HeaderBag(['Authorization'=>'Bearer ' . $issued->secret]),
            server:['REMOTE_ADDR'=>'127.0.0.1'],
        ));
        self::assertSame(200, $notifications->status());
        self::assertStringContainsString('"title":"Private alert"', $notifications->body());
        self::assertSame('private, no-store', $notifications->headers()->first('cache-control'));
        self::assertNotNull($notifications->headers()->first('x-ratelimit-limit'));
        self::assertCount(1, $audit->events);
        self::assertSame('api', $audit->events[0]->scope->value);
        self::assertSame('api.v1.notifications.index', $audit->events[0]->targetId);
        self::assertStringNotContainsString($issued->secret, json_encode($audit->events[0]->after, JSON_THROW_ON_ERROR));

        $support = $router->handle(new Request(
            HttpMethod::Get,
            '/api/v1/support/tickets',
            new HeaderBag(['Authorization'=>'Bearer ' . $issued->secret]),
            server:['REMOTE_ADDR'=>'127.0.0.1'],
        ));
        self::assertSame(403, $support->status());
        self::assertStringContainsString('"code":"insufficient_scope"', $support->body());
    }

    public function testInvalidPresentedCredentialDoesNotFallBackToAnonymousPublicAccess(): void
    {
        $router = $this->router(new ApiSecurityCredentialRepositoryFixture(), new ApiAuditRecorderFixture());

        $response = $router->handle(new Request(
            HttpMethod::Get,
            '/api/v1/forums',
            new HeaderBag(['Authorization'=>'Bearer fxpat_' . str_repeat('A', 43)]),
            server:['REMOTE_ADDR'=>'127.0.0.1'],
        ));

        self::assertSame(401, $response->status());
        self::assertStringContainsString('"code":"invalid_credential"', $response->body());
    }

    private function router(
        ApiSecurityCredentialRepositoryFixture $credentials,
        ApiAuditRecorderFixture $audit,
    ): Router {
        $routes = new RouteCollection();
        ApiV1RouteRegistrar::register(
            $routes,
            new ApiSecurityPublicReadsFixture(),
            new ApiSecurityPrivateReadsFixture(),
            new ApiV1CredentialResolver($credentials),
            new InMemoryRateLimitStore(),
            $audit,
        );

        return new Router($routes);
    }
}

final class ApiSecurityPublicReadsFixture implements PublicApiV1ReadRepository
{
    public function user(string $userId): ?array { return null; }
    public function forums(int $page, int $perPage): ApiV1Page { return new ApiV1Page([], $page, $perPage, false); }
    public function forum(string $forumId): ?array { return null; }
    public function threads(string $forumId, int $page, int $perPage): ?ApiV1Page { return null; }
    public function thread(string $threadId): ?array { return null; }
    public function posts(string $threadId, int $page, int $perPage): ?ApiV1Page { return null; }
    public function post(string $postId): ?array { return null; }
    public function modules(int $page, int $perPage): ApiV1Page { return new ApiV1Page([], $page, $perPage, false); }
    public function marketplace(int $page, int $perPage): ApiV1Page { return new ApiV1Page([], $page, $perPage, false); }
    public function marketplaceListing(string $listingId): ?array { return null; }
    public function supportCategories(int $page, int $perPage): ApiV1Page { return new ApiV1Page([], $page, $perPage, false); }
}

final class ApiSecurityPrivateReadsFixture implements PrivateApiV1ReadRepository
{
    public function conversations(EntityId $userId, int $page, int $perPage): ApiV1Page
    {
        return new ApiV1Page([['id'=>str_repeat('1',32),'type'=>'support','title'=>'Private conversation','status'=>'open']], $page, $perPage, false);
    }

    public function notifications(EntityId $userId, int $page, int $perPage): ApiV1Page
    {
        return new ApiV1Page([['id'=>str_repeat('2',32),'title'=>'Private alert']], $page, $perPage, false);
    }

    public function supportTickets(EntityId $userId, int $page, int $perPage): ApiV1Page
    {
        return new ApiV1Page([['id'=>str_repeat('3',32),'subject'=>'Private ticket']], $page, $perPage, false);
    }
}

final class ApiSecurityCredentialRepositoryFixture implements ApiV1CredentialRepository
{
    /** @var array<string,ApiV1CredentialRecord> */
    private array $records = [];

    public function findBySecretHash(string $secretHash): ?ApiV1CredentialRecord
    {
        return $this->records[$secretHash] ?? null;
    }

    public function save(ApiV1CredentialRecord $record): void
    {
        $this->records[$record->secretHash] = $record;
    }

    public function markUsed(EntityId $credentialId, DateTimeImmutable $at): void
    {
    }

    public function revoke(EntityId $credentialId, EntityId $ownerUserId, DateTimeImmutable $at): void
    {
    }
}

final class ApiAuditRecorderFixture implements AuditRecorder
{
    /** @var list<AuditEvent> */
    public array $events = [];

    public function append(AuditEvent $event): void
    {
        $this->events[] = $event;
    }

    public function mutate(AuditEvent $event, callable $mutation): mixed
    {
        $result = $mutation();
        $this->append($event);

        return $result;
    }
}
