<?php

declare(strict_types=1);

namespace Forwext\Core\Support\Intake;

use Forwext\Core\Support\Ticket\SupportTicket;

final readonly class SupportTicketSubmissionReceipt
{
    /**
     * @param array<string,SupportFieldValue> $fieldValues
     * @param list<SupportAttachmentRecord> $attachments
     */
    public function __construct(
        public SupportTicket $ticket,
        public array $fieldValues,
        public ?SupportContextLink $context,
        public array $attachments,
    ) {
    }
}
