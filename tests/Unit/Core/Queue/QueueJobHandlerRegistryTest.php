<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Queue;

use DateTimeImmutable;
use Forwext\Core\Addon\AddonId;
use Forwext\Core\Queue\QueueJobHandler;
use Forwext\Core\Queue\QueueJobHandlerRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class QueueJobHandlerRegistryTest extends TestCase
{
    public function testAddonHandlersRequireOwnerNamespaceAndDuplicateTypesFailClosed(): void
    {
        $registry = new QueueJobHandlerRegistry();
        $addon = AddonId::fromString('Acme/Demo');
        $handler = new QueueHandlerFixture('addon.acme.demo.rebuild');

        $registry->registerAddon($addon, $handler);
        $registered = $registry->require('addon.acme.demo.rebuild');

        self::assertSame('addon:Acme/Demo', $registered->owner->value());
        self::assertSame($handler, $registered->handler);

        $this->expectException(InvalidArgumentException::class);
        $registry->registerAddon($addon, new QueueHandlerFixture('addon.acme.demo.rebuild'));
    }

    public function testAddonHandlerCannotClaimAnotherNamespace(): void
    {
        $registry = new QueueJobHandlerRegistry();

        $this->expectException(InvalidArgumentException::class);
        $registry->registerAddon(
            AddonId::fromString('Acme/Demo'),
            new QueueHandlerFixture('addon.other.product.rebuild'),
        );
    }
}

final readonly class QueueHandlerFixture implements QueueJobHandler
{
    public function __construct(private string $type)
    {
    }

    public function jobType(): string
    {
        return $this->type;
    }

    public function handle(string $payload, DateTimeImmutable $now): int
    {
        return strlen($payload);
    }
}
