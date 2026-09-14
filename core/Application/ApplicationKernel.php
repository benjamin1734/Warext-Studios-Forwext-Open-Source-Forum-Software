<?php

declare(strict_types=1);

namespace Forwext\Core\Application;

use Forwext\Core\Container\Container;
use LogicException;
use Throwable;

final class ApplicationKernel
{
    /** @var list<ServiceProviderInterface> */
    private array $providers = [];

    private KernelState $state = KernelState::Created;

    /** @param iterable<ServiceProviderInterface> $providers */
    public function __construct(
        private readonly Container $container = new Container(),
        iterable $providers = [],
    ) {
        foreach ($providers as $provider) {
            $this->addProvider($provider);
        }
    }

    /** @param iterable<ServiceProviderInterface> $providers */
    public static function forTesting(iterable $providers = []): self
    {
        return new self(Container::forTesting(), $providers);
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function state(): KernelState
    {
        return $this->state;
    }

    public function addProvider(ServiceProviderInterface $provider): void
    {
        if ($this->state !== KernelState::Created) {
            throw new LogicException('Service providers can only be added before the kernel starts booting.');
        }

        $this->providers[] = $provider;
    }

    public function boot(): void
    {
        if ($this->state === KernelState::Booted) {
            return;
        }

        if ($this->state !== KernelState::Created) {
            throw new LogicException(sprintf('Kernel cannot boot from state "%s".', $this->state->value));
        }

        $this->state = KernelState::Booting;

        try {
            foreach ($this->providers as $provider) {
                $provider->register($this->container);
            }

            foreach ($this->providers as $provider) {
                $provider->boot($this->container);
            }

            $this->state = KernelState::Booted;
        } catch (Throwable $throwable) {
            $this->state = KernelState::Failed;
            throw $throwable;
        }
    }

    public function terminate(): void
    {
        if ($this->state === KernelState::Booting) {
            throw new LogicException('Kernel cannot terminate while it is booting.');
        }

        $this->state = KernelState::Terminated;
    }
}
