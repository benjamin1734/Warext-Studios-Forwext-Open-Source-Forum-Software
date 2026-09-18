<?php

declare(strict_types=1);

namespace Forwext\Core\Support\Intake;

use Forwext\Core\Domain\Entity\EntityId;

interface SupportTicketIntakeRepository
{
    /** @return list<SupportFieldDefinition> */
    public function activeFields(string $categoryKey): array;

    public function saveFieldDefinition(SupportFieldDefinition $definition): void;

    public function saveIntake(EntityId $ticketId, string $description): void;

    /** @param array<string,SupportFieldValue> $values */
    public function saveFieldValues(EntityId $ticketId, array $values): void;

    public function saveContext(EntityId $ticketId, SupportContextLink $context): void;

    public function saveAttachment(SupportAttachmentRecord $attachment): void;

    /** @return array<string,SupportFieldValue> */
    public function fieldValues(EntityId $ticketId): array;

    public function context(EntityId $ticketId): ?SupportContextLink;

    /** @return list<SupportAttachmentRecord> */
    public function attachments(EntityId $ticketId): array;
}
