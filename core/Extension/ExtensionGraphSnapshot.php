<?php

declare(strict_types=1);

namespace Forwext\Core\Extension;

use Forwext\Core\Container\ContainerServiceDiagnostic;
use Forwext\Core\Domain\Event\DomainEventListenerDiagnostic;

final readonly class ExtensionGraphSnapshot
{
    /**
     * @param list<ContainerServiceDiagnostic> $services
     * @param list<DomainEventListenerDiagnostic> $listeners
     */
    public function __construct(
        public array $services,
        public array $listeners,
    ) {
    }

    /** @return list<string> */
    public function issues(): array
    {
        $issues = [];
        foreach ($this->services as $service) {
            foreach ($service->issues as $issue) {
                $issues[] = $service->serviceId . ':' . $issue;
            }
        }

        return $issues;
    }
}
