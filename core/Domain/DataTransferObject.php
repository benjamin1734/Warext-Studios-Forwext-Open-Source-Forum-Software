<?php

declare(strict_types=1);

namespace Forwext\Core\Domain;

/**
 * Marker for immutable boundary data objects.
 *
 * DTOs transport validated/normalized data across application boundaries and
 * must not contain persistence, authorization or side-effecting domain logic.
 */
interface DataTransferObject
{
}
