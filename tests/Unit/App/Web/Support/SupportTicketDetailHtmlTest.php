<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web\Support;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Support\SupportTicketDetailCapabilities;
use Forwext\App\Web\Support\SupportTicketDetailHtml;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Support\Conversation\SupportConversationMessage;
use Forwext\Core\Support\Conversation\SupportConversationView;
use Forwext\Core\Support\Conversation\SupportMessageRole;
use Forwext\Core\Support\Conversation\SupportMessageVisibility;
use Forwext\Core\Support\Intake\SupportContextLink;
use Forwext\Core\Support\Intake\SupportContextType;
use Forwext\Core\Support\Intake\SupportFieldType;
use Forwext\Core\Support\Intake\SupportFieldValue;
use Forwext\Core\Support\Ticket\SupportSlaMetadata;
use Forwext\Core\Support\Ticket\SupportTicket;
use Forwext\Core\Support\Ticket\SupportTicketPriority;
use Forwext\Core\Support\Ticket\SupportTicketStatus;
use PHPUnit\Framework\TestCase;

final class SupportTicketDetailHtmlTest extends TestCase
{
    public function testTicketConversationAndIntakeContentAreEscaped(): void
    {
        $now = new DateTimeImmutable('2026-09-18 17:00:00', new DateTimeZone('UTC'));
        $requester = EntityId::fromString(str_repeat('1', 32));
        $ticket = new SupportTicket(
            EntityId::fromString(str_repeat('a', 32)),
            'general',
            $requester,
            null,
            '<script>alert(1)</script>',
            SupportTicketPriority::Normal,
            SupportTicketStatus::Open,
            new SupportSlaMetadata(null, null),
            null,
            $now,
            $now,
            1,
        );
        $message = new SupportConversationMessage(
            EntityId::fromString(str_repeat('b', 32)),
            $ticket->ticketId,
            $requester,
            SupportMessageRole::Requester,
            SupportMessageVisibility::Public,
            '<img src=x onerror=alert(2)>',
            null,
            null,
            null,
            $now,
        );

        $html = SupportTicketDetailHtml::page(
            new SupportConversationView($ticket, [$message], [], [], null, [], false),
            '<svg onload=alert(3)>',
            ['details'=>new SupportFieldValue(SupportFieldType::Text, '<b>unsafe</b>')],
            new SupportContextLink(
                SupportContextType::MarketplaceListing,
                EntityId::fromString(str_repeat('c', 32)),
                '<iframe>bad</iframe>',
            ),
            [],
            'csrf-token',
            new BasePath('/community'),
            new SupportTicketDetailCapabilities(true, false, false, false, false, false, false, false, false),
            '<Admin>',
            null,
        );

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringNotContainsString('<img src=x onerror=alert(2)>', $html);
        self::assertStringNotContainsString('<svg onload=alert(3)>', $html);
        self::assertStringNotContainsString('<iframe>bad</iframe>', $html);
        self::assertStringNotContainsString('<b>unsafe</b>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(2)&gt;', $html);
        self::assertStringContainsString('/community/support/tickets/', $html);
        self::assertStringContainsString('name="_csrf" value="csrf-token"', $html);
    }
}
