<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Addon;

use DateTimeImmutable;
use Forwext\Core\Addon\AddonDataState;
use Forwext\Core\Addon\AddonId;
use Forwext\Core\Addon\AddonInstallation;
use Forwext\Core\Addon\AddonManifest;
use Forwext\Core\Addon\AddonRepository;
use Forwext\Core\Addon\AddonState;
use Forwext\Core\Addon\Backend\AddonBackendRegistration;
use Forwext\Core\Addon\Backend\AddonBackendRegistry;
use Forwext\Core\Addon\Backend\AddonBackendRuntimeActivator;
use Forwext\Core\Addon\Backend\AddonBackendRuntimeIntegrator;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Notification\NotificationRegistry;
use Forwext\Core\Queue\QueueJobHandlerRegistry;
use Forwext\Core\Routing\PathTemplate;
use Forwext\Core\Routing\Route;
use Forwext\Core\Routing\RouteCollection;
use Forwext\Core\Scheduler\SchedulerRegistry;
use Forwext\Core\Search\Lifecycle\SearchContentSourceRegistry;
use PHPUnit\Framework\TestCase;

final class AddonBackendRuntimeActivatorTest extends TestCase
{
    public function testOnlyPersistedEnabledAddonsReachRuntimeRegistries(): void
    {
        $enabled = $this->registration('Acme/Enabled', 'addon.acme.enabled.index', '/enabled-addon');
        $disabled = $this->registration('Acme/Disabled', 'addon.acme.disabled.index', '/disabled-addon');
        $unknown = $this->registration('Acme/Unknown', 'addon.acme.unknown.index', '/unknown-addon');

        $registry = new AddonBackendRegistry();
        $registry->register($disabled);
        $registry->register($unknown);
        $registry->register($enabled);

        $repository = new BackendActivationAddonRepositoryFixture([
            'Acme/Enabled'=>$this->installation('Acme/Enabled', AddonState::Enabled),
            'Acme/Disabled'=>$this->installation('Acme/Disabled', AddonState::Disabled),
        ]);
        $routes = new RouteCollection();

        $applied = (new AddonBackendRuntimeActivator(
            $repository,
            new AddonBackendRuntimeIntegrator(),
        ))->applyEnabledRegistry(
            $registry,
            $routes,
            new QueueJobHandlerRegistry(),
            new SchedulerRegistry(),
            new SearchContentSourceRegistry(),
            new NotificationRegistry(),
        );

        self::assertSame(['Acme/Enabled'], $applied);
        self::assertSame(
            ['addon.acme.enabled.index'],
            array_map(static fn (Route $route): string => $route->name(), $routes->all()),
        );
    }

    private function registration(string $id, string $routeName, string $path): AddonBackendRegistration
    {
        $registration = new AddonBackendRegistration(AddonId::fromString($id));
        $registration->route(new Route(
            $routeName,
            [HttpMethod::Get],
            new PathTemplate($path),
            new BackendActivationRouteHandlerFixture(),
        ));

        return $registration;
    }

    private function installation(string $id, AddonState $state): AddonInstallation
    {
        $manifest = AddonManifest::fromJson((string) json_encode([
            'id'=>$id,
            'version'=>'1.0.0',
            'title'=>$id,
            'description'=>'Runtime activation test fixture.',
            'requires'=>[
                'forwext'=>'0.0.1',
                'addons'=>[],
            ],
            'conflicts'=>[
                'addons'=>[],
            ],
            'data_retention'=>'retain_only',
        ], JSON_THROW_ON_ERROR));

        return new AddonInstallation(
            $manifest,
            $state,
            AddonDataState::Retained,
            str_repeat('a', 64),
        );
    }
}

final class BackendActivationAddonRepositoryFixture implements AddonRepository
{
    /** @param array<string,AddonInstallation> $items */
    public function __construct(private array $items)
    {
    }

    public function find(AddonId $id): ?AddonInstallation
    {
        return $this->items[$id->value()] ?? null;
    }

    public function all(): array
    {
        return $this->items;
    }

    public function save(AddonInstallation $installation, EntityId $actor, DateTimeImmutable $at): void
    {
        $this->items[$installation->manifest->id->value()] = $installation;
    }
}

final readonly class BackendActivationRouteHandlerFixture implements RequestHandlerInterface
{
    public function handle(Request $request): Response
    {
        return Response::text('enabled');
    }
}
