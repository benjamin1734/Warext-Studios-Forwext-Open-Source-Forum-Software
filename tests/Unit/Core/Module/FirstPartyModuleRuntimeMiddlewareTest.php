<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Module;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\CallableRequestHandler;
use Forwext\Core\Http\Middleware\MiddlewareInterface;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Module\FirstParty\FirstPartyModuleConditionalMiddleware;
use Forwext\Core\Module\FirstParty\FirstPartyModuleDataState;
use Forwext\Core\Module\FirstParty\FirstPartyModuleRecord;
use Forwext\Core\Module\FirstParty\FirstPartyModuleRegistry;
use Forwext\Core\Module\FirstParty\FirstPartyModuleRepository;
use Forwext\Core\Module\FirstParty\FirstPartyModuleRouteMiddleware;
use Forwext\Core\Module\FirstParty\FirstPartyModuleScope;
use Forwext\Core\Module\FirstParty\FirstPartyModuleState;
use Forwext\Core\Routing\Router;
use PHPUnit\Framework\TestCase;

final class FirstPartyModuleRuntimeMiddlewareTest extends TestCase
{
    public function testRouteMiddlewareAllowsEnabledAndFailsClosedForDisabledOrUninstalled(): void
    {
        $registry = FirstPartyModuleRegistry::withCoreDefaults();
        $repository = new RuntimeModuleRepository();
        $middleware = new FirstPartyModuleRouteMiddleware($registry, $repository);
        $next = new CallableRequestHandler(static fn (Request $request): Response => Response::text('ok'));

        $request = (new Request(HttpMethod::Get, '/marketplace'))
            ->withAttribute(Router::ATTRIBUTE_ROUTE_NAME, 'marketplace.index');

        self::assertSame(200, $middleware->process($request, $next)->status());

        $repository->set('marketplace', FirstPartyModuleState::Disabled);
        $disabled = $middleware->process($request, $next);
        self::assertSame(503, $disabled->status());
        self::assertSame('3600', $disabled->headers()->first('Retry-After'));

        $repository->set('marketplace', FirstPartyModuleState::Uninstalled);
        self::assertSame(404, $middleware->process($request, $next)->status());
    }

    public function testConditionalMiddlewareSkipsAmbientBehaviorWhenModuleIsNotEnabled(): void
    {
        $registry = FirstPartyModuleRegistry::withCoreDefaults();
        $repository = new RuntimeModuleRepository();
        $inner = new CountingRuntimeMiddleware();
        $middleware = new FirstPartyModuleConditionalMiddleware(
            'analytics',
            $registry,
            $repository,
            $inner,
        );
        $next = new CallableRequestHandler(static fn (Request $request): Response => new Response('', 204));
        $request = new Request(HttpMethod::Get, '/');

        $repository->set('analytics', FirstPartyModuleState::Disabled);
        self::assertSame(204, $middleware->process($request, $next)->status());
        self::assertSame(0, $inner->calls);

        $repository->set('analytics', FirstPartyModuleState::Enabled);
        self::assertSame(204, $middleware->process($request, $next)->status());
        self::assertSame(1, $inner->calls);
    }
}

/** @internal */
final class CountingRuntimeMiddleware implements MiddlewareInterface
{
    public int $calls = 0;

    public function process(Request $request, RequestHandlerInterface $next): Response
    {
        ++$this->calls;

        return $next->handle($request);
    }
}

/** @internal */
final class RuntimeModuleRepository implements FirstPartyModuleRepository
{
    /** @var array<string,FirstPartyModuleRecord> */
    private array $states = [];

    public function set(string $key, FirstPartyModuleState $state): void
    {
        $this->states[$key] = new FirstPartyModuleRecord(
            $key,
            $state,
            FirstPartyModuleDataState::Retained,
            null,
            null,
        );
    }

    public function state(string $moduleKey): FirstPartyModuleRecord
    {
        return $this->states[$moduleKey] ?? FirstPartyModuleRecord::defaultEnabled($moduleKey);
    }

    public function states(): array
    {
        return $this->states;
    }

    public function saveState(
        string $moduleKey,
        FirstPartyModuleState $state,
        FirstPartyModuleDataState $dataState,
        EntityId $actor,
        DateTimeImmutable $at,
    ): void {
        $this->states[$moduleKey] = new FirstPartyModuleRecord($moduleKey, $state, $dataState, $actor, $at);
    }

    public function settings(string $moduleKey, FirstPartyModuleScope $scope, string $scopeId): array
    {
        return [];
    }

    public function saveSetting(
        string $moduleKey,
        FirstPartyModuleScope $scope,
        string $scopeId,
        string $settingKey,
        bool|int|string $value,
        EntityId $actor,
        DateTimeImmutable $at,
    ): void {
    }

    public function deleteSetting(
        string $moduleKey,
        FirstPartyModuleScope $scope,
        string $scopeId,
        string $settingKey,
    ): void {
    }

    public function deleteSettings(string $moduleKey): void
    {
    }

    public function queueStoragePaths(string $moduleKey, array $paths, DateTimeImmutable $at): void
    {
    }

    public function pendingStoragePaths(string $moduleKey, int $limit = 500): array
    {
        return [];
    }

    public function markStoragePathPurged(string $moduleKey, string $path): void
    {
    }

    public function markStoragePathFailure(
        string $moduleKey,
        string $path,
        string $error,
        DateTimeImmutable $at,
    ): void {
    }

    public function pendingStoragePathCount(string $moduleKey): int
    {
        return 0;
    }
}
