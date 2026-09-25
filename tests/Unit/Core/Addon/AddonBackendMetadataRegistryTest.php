<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Addon;

use Forwext\Core\Addon\AddonId;
use Forwext\Core\Addon\Backend\AddonBackendMetadataRegistry;
use Forwext\Core\Addon\Backend\AddonBackendRegistration;
use Forwext\Core\Addon\Backend\AddonBackendRuntimeIntegrator;
use Forwext\Core\Addon\Backend\AddonContentTypeDefinition;
use Forwext\Core\Addon\Backend\AddonEntityDefinition;
use Forwext\Core\Addon\Backend\AddonWebhookDefinition;
use Forwext\Core\Addon\Backend\AddonWebhookDirection;
use Forwext\Core\Domain\Entity\Entity;
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
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AddonBackendMetadataRegistryTest extends TestCase
{
    public function testRuntimeIntegratorRegistersEntityWebhookAndContentTypeMetadata(): void
    {
        $registration = new AddonBackendRegistration(AddonId::fromString('Acme/Demo'));
        $registration
            ->entity(new AddonEntityDefinition(
                'addon.acme.demo.record',
                BackendMetadataEntityFixture::class,
                'Demo record',
            ))
            ->webhook(new AddonWebhookDefinition(
                'addon.acme.demo.outbound',
                AddonWebhookDirection::Outbound,
                'demo.record.changed',
                'Notify an external integration when a demo record changes.',
            ))
            ->contentType(new AddonContentTypeDefinition(
                'addon.acme.demo.content',
                BackendMetadataEntityFixture::class,
                'Demo content',
                searchable: false,
                reportable: true,
            ));

        $metadata = new AddonBackendMetadataRegistry();
        $integrator = new AddonBackendRuntimeIntegrator($metadata);
        $integrator->apply(
            $registration,
            new RouteCollection(),
            new QueueJobHandlerRegistry(),
            new SchedulerRegistry(),
            new SearchContentSourceRegistry(),
            new NotificationRegistry(),
        );

        self::assertSame('Acme/Demo', $metadata->entityOwner('addon.acme.demo.record'));
        self::assertSame('Acme/Demo', $metadata->webhookOwner('addon.acme.demo.outbound'));
        self::assertSame('Acme/Demo', $metadata->contentTypeOwner('addon.acme.demo.content'));
        self::assertCount(1, $metadata->entities());
        self::assertCount(1, $metadata->webhooks());
        self::assertCount(1, $metadata->contentTypes());
    }

    public function testMetadataConflictIsPreflightedBeforeAnyRealRuntimeRegistryIsMutated(): void
    {
        $owner = AddonId::fromString('Acme/Demo');
        $metadata = new AddonBackendMetadataRegistry();
        $metadata->registerContentType($owner, new AddonContentTypeDefinition(
            'addon.acme.demo.content',
            BackendMetadataEntityFixture::class,
            'Existing content',
        ));

        $registration = new AddonBackendRegistration($owner);
        $registration
            ->route(new Route(
                'addon.acme.demo.index',
                [HttpMethod::Get],
                new PathTemplate('/addon-demo'),
                new BackendMetadataRouteHandlerFixture(),
            ))
            ->contentType(new AddonContentTypeDefinition(
                'addon.acme.demo.content',
                BackendMetadataEntityFixture::class,
                'Conflicting content',
            ));

        $routes = new RouteCollection();

        try {
            (new AddonBackendRuntimeIntegrator($metadata))->apply(
                $registration,
                $routes,
                new QueueJobHandlerRegistry(),
                new SchedulerRegistry(),
                new SearchContentSourceRegistry(),
                new NotificationRegistry(),
            );
            self::fail('Expected duplicate metadata registration to fail.');
        } catch (InvalidArgumentException) {
            self::assertSame([], $routes->all());
            self::assertSame('Existing content', $metadata->contentType('addon.acme.demo.content')?->label);
        }
    }
}

final readonly class BackendMetadataEntityFixture implements Entity
{
    public function id(): EntityId
    {
        return EntityId::fromString('demo-record');
    }
}

final readonly class BackendMetadataRouteHandlerFixture implements RequestHandlerInterface
{
    public function handle(Request $request): Response
    {
        return Response::text('demo');
    }
}
