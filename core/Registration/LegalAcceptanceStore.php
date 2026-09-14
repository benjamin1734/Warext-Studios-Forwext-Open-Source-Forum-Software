<?php

declare(strict_types=1);

namespace Forwext\Core\Registration;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

interface LegalAcceptanceStore
{
    public function record(
        EntityId $userId,
        LegalDocumentRequirement $document,
        DateTimeImmutable $acceptedAt,
        string $clientFingerprint,
    ): void;
}
