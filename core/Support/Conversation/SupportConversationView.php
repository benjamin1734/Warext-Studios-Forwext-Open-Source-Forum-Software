<?php

declare(strict_types=1);

namespace Forwext\Core\Support\Conversation;

use Forwext\Core\Support\Ticket\SupportTicket;

final readonly class SupportConversationView
{
    /**
     * @param list<SupportConversationMessage> $messages
     * @param list<SupportTicketHistoryEntry> $history
     * @param list<SupportTicketRelation> $relations
     * @param list<SupportCannedResponse> $cannedResponses
     */
    public function __construct(
        public SupportTicket $ticket,
        public array $messages,
        public array $history,
        public array $relations,
        public ?SupportEscalationState $escalation,
        public array $cannedResponses,
        public bool $staffView,
    ) {
    }
}
