<?php

declare(strict_types=1);

namespace Forwext\Core\Audit;

interface AuditRecorder
{
    public function append(AuditEvent $event): void;

    /** @template T @param callable():T $mutation @return T */
    public function mutate(AuditEvent $event, callable $mutation): mixed;
}
