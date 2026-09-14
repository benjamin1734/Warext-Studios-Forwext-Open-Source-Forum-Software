<?php

declare(strict_types=1);

namespace Forwext\Core\Application\Service;

/**
 * Marker for use-case orchestration services.
 *
 * Application services coordinate authorization-aware use cases, repositories,
 * domain services, transactions and domain-event publication. They must not
 * become a second copy of domain business rules.
 */
interface ApplicationService
{
}
