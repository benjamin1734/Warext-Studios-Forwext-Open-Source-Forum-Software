<?php

declare(strict_types=1);

namespace Forwext\Core\Support\Conversation;

use Forwext\Core\Domain\Entity\EntityId;

interface SupportConversationRepository
{
    public function appendMessage(SupportConversationMessage $message): void;
    public function message(EntityId $messageId): ?SupportConversationMessage;

    /** @return list<SupportConversationMessage> */
    public function messages(EntityId $ticketId, bool $includeInternal, int $limit = 200): array;

    public function appendHistory(SupportTicketHistoryEntry $entry): void;

    /** @return list<SupportTicketHistoryEntry> */
    public function history(EntityId $ticketId, bool $includeStaff, int $limit = 200): array;

    public function saveCannedResponse(SupportCannedResponse $response): void;
    public function cannedResponse(string $key): ?SupportCannedResponse;

    /** @return list<SupportCannedResponse> */
    public function activeCannedResponses(): array;

    public function escalation(EntityId $ticketId): ?SupportEscalationState;
    public function setEscalation(SupportEscalationState $state): void;

    public function addRelation(SupportTicketRelation $relation): void;

    /** @return list<SupportTicketRelation> */
    public function relations(EntityId $ticketId): array;
}
