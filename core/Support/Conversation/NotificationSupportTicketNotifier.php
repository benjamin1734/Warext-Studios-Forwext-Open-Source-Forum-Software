<?php

declare(strict_types=1);

namespace Forwext\Core\Support\Conversation;

use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Notification\NotificationDefinition;
use Forwext\Core\Notification\NotificationDispatcher;
use Forwext\Core\Notification\NotificationRegistry;
use Forwext\Core\Notification\NotificationRequest;
use Forwext\Core\Support\Ticket\SupportTicket;

final readonly class NotificationSupportTicketNotifier implements SupportTicketNotifier
{
    public const STAFF_REPLY = 'support.ticket.staff_reply';
    public const REQUESTER_REPLY = 'support.ticket.requester_reply';
    public const ASSIGNED = 'support.ticket.assigned';
    public const STATUS = 'support.ticket.status';
    public const SPLIT = 'support.ticket.split';

    public function __construct(private NotificationDispatcher $dispatcher)
    {
    }

    public static function registerDefinitions(NotificationRegistry $registry): void
    {
        $registry->register(new NotificationDefinition(
            self::STAFF_REPLY,
            'support',
            'Destek talebinize yanıt geldi',
            '{{subject}} başlıklı destek talebinize yeni bir yetkili yanıtı geldi.',
        ));
        $registry->register(new NotificationDefinition(
            self::REQUESTER_REPLY,
            'support',
            'Atandığınız talebe yeni yanıt geldi',
            '{{subject}} başlıklı destek talebine kullanıcı yeni bir yanıt yazdı.',
        ));
        $registry->register(new NotificationDefinition(
            self::ASSIGNED,
            'support',
            'Destek talebi size atandı',
            '{{subject}} başlıklı destek talebi size atandı.',
        ));
        $registry->register(new NotificationDefinition(
            self::STATUS,
            'support',
            'Destek talebi durumu güncellendi',
            '{{subject}} başlıklı talebinizin durumu: {{status}}.',
        ));
        $registry->register(new NotificationDefinition(
            self::SPLIT,
            'support',
            'Yeni destek talebi oluşturuldu',
            '{{subject}} başlıklı talebiniz mevcut bir destek konuşmasından ayrıldı.',
        ));
    }

    public function staffReply(SupportTicket $ticket, SupportConversationMessage $message): void
    {
        if ($ticket->requesterUserId === null) {
            return;
        }
        $this->dispatcher->dispatch(new NotificationRequest(
            $ticket->requesterUserId,
            self::STAFF_REPLY,
            ['subject'=>$ticket->subject],
            'support-ticket:' . $ticket->ticketId->value(),
            'support-staff-reply:' . $message->messageId->value(),
            '/support/tickets/' . rawurlencode($ticket->ticketId->value()),
            ['ticket_id'=>$ticket->ticketId->value(),'message_id'=>$message->messageId->value()],
        ));
    }

    public function requesterReply(SupportTicket $ticket, SupportConversationMessage $message): void
    {
        if ($ticket->assignedUserId === null || $ticket->assignedUserId->equals($message->authorUserId ?? $ticket->assignedUserId)) {
            return;
        }
        $this->dispatcher->dispatch(new NotificationRequest(
            $ticket->assignedUserId,
            self::REQUESTER_REPLY,
            ['subject'=>$ticket->subject],
            'support-ticket:' . $ticket->ticketId->value(),
            'support-requester-reply:' . $message->messageId->value(),
            '/support/tickets/' . rawurlencode($ticket->ticketId->value()),
            ['ticket_id'=>$ticket->ticketId->value(),'message_id'=>$message->messageId->value()],
        ));
    }

    public function assigned(SupportTicket $ticket, EntityId $assigneeUserId): void
    {
        $this->dispatcher->dispatch(new NotificationRequest(
            $assigneeUserId,
            self::ASSIGNED,
            ['subject'=>$ticket->subject],
            null,
            'support-assigned:' . $ticket->ticketId->value() . ':' . $assigneeUserId->value(),
            '/support/tickets/' . rawurlencode($ticket->ticketId->value()),
            ['ticket_id'=>$ticket->ticketId->value()],
        ));
    }

    public function statusChanged(SupportTicket $ticket): void
    {
        if ($ticket->requesterUserId === null) {
            return;
        }
        $this->dispatcher->dispatch(new NotificationRequest(
            $ticket->requesterUserId,
            self::STATUS,
            ['subject'=>$ticket->subject,'status'=>$ticket->status->label()],
            'support-ticket:' . $ticket->ticketId->value(),
            'support-status:' . $ticket->ticketId->value() . ':' . $ticket->status->value . ':' . $ticket->version,
            '/support/tickets/' . rawurlencode($ticket->ticketId->value()),
            ['ticket_id'=>$ticket->ticketId->value(),'status'=>$ticket->status->value],
        ));
    }

    public function splitCreated(SupportTicket $source, SupportTicket $created): void
    {
        if ($created->requesterUserId === null) {
            return;
        }
        $this->dispatcher->dispatch(new NotificationRequest(
            $created->requesterUserId,
            self::SPLIT,
            ['subject'=>$created->subject],
            null,
            'support-split:' . $created->ticketId->value(),
            '/support/tickets/' . rawurlencode($created->ticketId->value()),
            ['ticket_id'=>$created->ticketId->value(),'source_ticket_id'=>$source->ticketId->value()],
        ));
    }
}
