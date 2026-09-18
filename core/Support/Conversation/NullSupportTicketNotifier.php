<?php

declare(strict_types=1);

namespace Forwext\Core\Support\Conversation;

use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Support\Ticket\SupportTicket;

final readonly class NullSupportTicketNotifier implements SupportTicketNotifier
{
    public function staffReply(SupportTicket $ticket, SupportConversationMessage $message): void {}
    public function requesterReply(SupportTicket $ticket, SupportConversationMessage $message): void {}
    public function assigned(SupportTicket $ticket, EntityId $assigneeUserId): void {}
    public function statusChanged(SupportTicket $ticket): void {}
    public function splitCreated(SupportTicket $source, SupportTicket $created): void {}
}
