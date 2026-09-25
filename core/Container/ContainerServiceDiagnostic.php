<?php

declare(strict_types=1);

namespace Forwext\Core\Container;

final readonly class ContainerServiceDiagnostic
{
    /**
     * @param list<ServiceDecoratorDiagnostic> $decorators
     * @param list<string> $issues
     */
    public function __construct(
        public string $serviceId,
        public ?string $bindingTarget,
        public ?ServiceLifetime $lifetime,
        public ?string $bindingOwner,
        public array $decorators,
        public array $issues,
    ) {
    }
}
