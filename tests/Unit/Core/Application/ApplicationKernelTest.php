<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Application;

use Forwext\Core\Application\ApplicationKernel;
use Forwext\Core\Application\KernelState;
use Forwext\Core\Application\ServiceProviderInterface;
use Forwext\Core\Container\Container;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ApplicationKernelTest extends TestCase
{
    public function testAllProvidersRegisterBeforeAnyProviderBoots(): void
    {
        $probe = new KernelProbe();
        $kernel = new ApplicationKernel(providers: [
            new ProbeProvider('first', $probe),
            new ProbeProvider('second', $probe),
        ]);

        $kernel->boot();

        self::assertSame(
            ['register:first', 'register:second', 'boot:first', 'boot:second'],
            $probe->events,
        );
        self::assertSame(KernelState::Booted, $kernel->state());
    }

    public function testBootIsIdempotentAfterSuccessfulBoot(): void
    {
        $probe = new KernelProbe();
        $kernel = new ApplicationKernel(providers: [new ProbeProvider('only', $probe)]);

        $kernel->boot();
        $kernel->boot();

        self::assertSame(['register:only', 'boot:only'], $probe->events);
    }

    public function testProviderCannotBeAddedAfterBoot(): void
    {
        $kernel = new ApplicationKernel();
        $kernel->boot();

        $this->expectException(LogicException::class);
        $kernel->addProvider(new ProbeProvider('late', new KernelProbe()));
    }

    public function testTestingKernelExposesOverrideEnabledContainer(): void
    {
        $kernel = ApplicationKernel::forTesting();
        $kernel->container()->instance('value', 'original');
        $kernel->container()->overrideInstance('value', 'replacement');

        self::assertSame('replacement', $kernel->container()->get('value'));
    }
}

final class KernelProbe
{
    /** @var list<string> */
    public array $events = [];
}

final readonly class ProbeProvider implements ServiceProviderInterface
{
    public function __construct(
        private string $name,
        private KernelProbe $probe,
    ) {
    }

    public function register(Container $container): void
    {
        $this->probe->events[] = 'register:' . $this->name;
        $container->singleton('provider.' . $this->name, static fn (): object => new class {
        });
    }

    public function boot(Container $container): void
    {
        $container->get('provider.' . $this->name);
        $this->probe->events[] = 'boot:' . $this->name;
    }
}
