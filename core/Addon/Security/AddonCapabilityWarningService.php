<?php

declare(strict_types=1);

namespace Forwext\Core\Addon\Security;

use Forwext\Core\Addon\AddonManifest;

final class AddonCapabilityWarningService
{
    /** @return list<AddonCapabilityWarning> */
    public function warnings(AddonManifest $manifest): array
    {
        $warnings = [];
        foreach ($manifest->capabilities as $capability) {
            [$risk, $message] = match ($capability) {
                AddonCapability::Database => [
                    AddonCapabilityRisk::High,
                    'May create or mutate persistent database state through declared migrations or services.',
                ],
                AddonCapability::Filesystem => [
                    AddonCapabilityRisk::High,
                    'May read or write server-side files through add-on code.',
                ],
                AddonCapability::OutboundNetwork => [
                    AddonCapabilityRisk::High,
                    'May initiate outbound requests; destinations and SSRF controls require review.',
                ],
                AddonCapability::BackgroundJobs => [
                    AddonCapabilityRisk::Caution,
                    'May enqueue background work that consumes queue/runtime resources.',
                ],
                AddonCapability::ScheduledTasks => [
                    AddonCapabilityRisk::Caution,
                    'May register recurring scheduled work.',
                ],
                AddonCapability::AdminUi => [
                    AddonCapabilityRisk::Caution,
                    'May add ACP surfaces; backend permissions remain authoritative.',
                ],
                AddonCapability::UserUi => [
                    AddonCapabilityRisk::Informational,
                    'May contribute visible native PHP or React UI extensions.',
                ],
                AddonCapability::ContentExtension => [
                    AddonCapabilityRisk::Caution,
                    'May extend content rendering, editing or content-type behavior.',
                ],
                AddonCapability::Permissions => [
                    AddonCapabilityRisk::High,
                    'May define permission-aware features; permission grants are never automatic.',
                ],
                AddonCapability::Webhooks => [
                    AddonCapabilityRisk::High,
                    'May declare webhook integration behavior; destination and delivery security require review.',
                ],
            };
            $warnings[] = new AddonCapabilityWarning($capability, $risk, $message);
        }

        return $warnings;
    }
}
