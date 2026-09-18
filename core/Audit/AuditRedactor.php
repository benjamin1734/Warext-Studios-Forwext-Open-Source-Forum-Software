<?php

declare(strict_types=1);

namespace Forwext\Core\Audit;

interface AuditRedactor
{
    /** @param array<string|int,mixed> $snapshot @return array<string|int,mixed> */
    public function redact(array $snapshot): array;
}
