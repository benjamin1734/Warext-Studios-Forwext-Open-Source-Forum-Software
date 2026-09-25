<?php

declare(strict_types=1);

namespace Forwext\Core\Addon;

/**
 * Destructive add-on data deletion boundary.
 *
 * Implementations must delete only data owned by the supplied add-on, be safe to
 * retry, and must not infer ownership from user-controlled filesystem paths.
 * The lifecycle service invokes purge inside the common audit mutation boundary;
 * database-backed purgers that share the active connection therefore participate
 * in the caller transaction.
 */
interface AddonDataPurger
{
    public function supports(AddonId $id): bool;

    public function purge(AddonInstallation $installation): void;
}
