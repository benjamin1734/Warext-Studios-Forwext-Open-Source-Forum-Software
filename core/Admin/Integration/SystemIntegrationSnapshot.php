<?php

declare(strict_types=1);

namespace Forwext\Core\Admin\Integration;

use Forwext\Core\Capability\CapabilityEntry;

final readonly class SystemIntegrationSnapshot
{
    /**
     * @param list<IntegrationSettingDefinition> $settings
     * @param list<IntegrationSecretDefinition> $secrets
     * @param array<string,mixed> $values
     * @param array<string,bool> $environmentOverrides
     * @param array<string,bool> $secretConfigured
     * @param list<CapabilityEntry> $capabilities
     */
    public function __construct(
        public array $settings,
        public array $secrets,
        public array $values,
        public array $environmentOverrides,
        public array $secretConfigured,
        public array $capabilities,
    ) {
    }
}
