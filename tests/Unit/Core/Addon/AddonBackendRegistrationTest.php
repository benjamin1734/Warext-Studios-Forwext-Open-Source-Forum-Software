<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Addon;

use DateTimeImmutable;
use Forwext\Core\Addon\AddonId;
use Forwext\Core\Addon\Backend\AddonBackendRegistration;
use Forwext\Core\Addon\Backend\AddonBackendRuntimeIntegrator;
use Forwext\Core\Domain\Access\Permission\PermissionCatalogEntry;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Access\Permission\PermissionValueType;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Notification\NotificationChannel;
use Forwext\Core\Notification\NotificationDefinition;
use Forwext\Core\Notification\NotificationRegistry;
use Forwext\Core\Queue\QueueJobHandler;
use Forwext\Core\Queue\QueueJobHandlerRegistry;
use Forwext\Core\Routing\PathTemplate;
use Forwext\Core\Routing\Route;
use Forwext\Core\Routing\RouteCollection;
use Forwext\Core\Routing\RoutingException;
use Forwext\Core\Scheduler\CronExpression;
use Forwext\Core\Scheduler\ScheduledTask;
use Forwext\Core\Scheduler\SchedulerRegistry;
use Forwext\Core\Search\Lifecycle\SearchContentPage;
use Forwext\Core\Search\Lifecycle\SearchContentSource;
use Forwext\Core\Search\Lifecycle\SearchContentSourceRegistry;
use Forwext\Core\Search\SearchDocument;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AddonBackendRegistrationTest extends TestCase
{
    public function testOwnedCapabilitiesRegisterIntoExistingRuntimeRegistries(): void
    {
        $addon = AddonId::fromString('Acme/Demo');
        $registration = new AddonBackendRegistration($addon);
        $registration
            ->route(new Route(
                'addon.acme.demo.index',
                [HttpMethod::Get],
                new PathTemplate('/demo'),
                new BackendRouteHandlerFixture(),
            ))
            ->permission(new PermissionCatalogEntry(
                PermissionKey::fromString('addon.acme.demo.use'),
                PermissionValueType::Flag,
                'Use the demo add-on.',
            ))
            ->job(new BackendJobHandlerFixture())
            ->scheduledTask(new ScheduledTask(
                'addon.acme.demo.hourly',
                CronExpression::parse('0 * * * *'),
                \Forwext\Core\Queue\QueueName::fromString('addons'),
                'addon.acme.demo.job',
            ))
            ->searchSource(new BackendSearchSourceFixture())
            ->notification(new NotificationDefinition(
                'addon.acme.demo.updated',
                'addon.acme.demo.general',
                'Demo updated',
                'Demo content was updated.',
                [NotificationChannel::InApp],
            ));

        $routes = new RouteCollection();
        $jobs = new QueueJobHandlerRegistry();
        $scheduler = new SchedulerRegistry();
        $search = new SearchContentSourceRegistry();
        $notifications = new NotificationRegistry();

        (new AddonBackendRuntimeIntegrator())->apply(
            $registration,
            $routes,
            $jobs,
            $scheduler,
            $search,
            $notifications,
        );

        self::assertSame('addon.acme.demo.index', $routes->get('addon.acme.demo.index')->name());
        self::assertSame('addon:Acme/Demo', $jobs->require('addon.acme.demo.job')->owner->value());
        self::assertCount(1, $scheduler->all());
        self::assertSame(['addon.acme.demo.document'], $search->types());
        self::assertTrue($notifications->has('addon.acme.demo.updated'));
        self::assertSame('addon.acme.demo.use', $registration->permissions()[0]->key()->value());
    }

    public function testRegistrationRejectsCapabilitiesOutsideAddonNamespace(): void
    {
        $registration = new AddonBackendRegistration(AddonId::fromString('Acme/Demo'));

        $this->expectException(InvalidArgumentException::class);
        $registration->permission(new PermissionCatalogEntry(
            PermissionKey::fromString('other.permission'),
            PermissionValueType::Flag,
            'Not owned.',
        ));
    }

    public function testRuntimePreflightPreventsPartialRegistrationWhenRouteConflicts(): void
    {
        $routes = new RouteCollection();
        $existing = new Route(
            'core.demo',
            [HttpMethod::Get],
            new PathTemplate('/demo'),
            new BackendRouteHandlerFixture(),
        );
        $routes->add($existing);

        $registration = new AddonBackendRegistration(AddonId::fromString('Acme/Demo'));
        $registration
            ->route(new Route(
                'addon.acme.demo.conflict',
                [HttpMethod::Get],
                new PathTemplate('/demo'),
                new BackendRouteHandlerFixture(),
            ))
            ->job(new BackendJobHandlerFixture());

        $jobs = new QueueJobHandlerRegistry();

        try {
            (new AddonBackendRuntimeIntegrator())->apply(
                $registration,
                $routes,
                $jobs,
                new SchedulerRegistry(),
                new SearchContentSourceRegistry(),
                new NotificationRegistry(),
            );
            self::fail('Expected route conflict.');
        } catch (RoutingException) {
            self::assertSame([], $jobs->all());
            self::assertCount(1, $routes->all());
        }
    }
}

final readonly class BackendRouteHandlerFixture implements RequestHandlerInterface
{
    public function handle(Request $request): Response
    {
        return Response::text('demo');
    }
}

final readonly class BackendJobHandlerFixture implements QueueJobHandler
{
    public function jobType(): string { return 'addon.acme.demo.job'; }
    public function handle(string $payload, DateTimeImmutable $now): int { return strlen($payload); }
}

final readonly class BackendSearchSourceFixture implements SearchContentSource
{
    public function documentType(): string { return 'addon.acme.demo.document'; }
    public function document(string $documentId): ?SearchDocument { return null; }
    public function scan(?string $afterId, int $limit): SearchContentPage
    {
        return new SearchContentPage([], null);
    }
}
