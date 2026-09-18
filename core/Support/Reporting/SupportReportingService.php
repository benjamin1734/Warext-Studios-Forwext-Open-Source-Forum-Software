<?php

declare(strict_types=1);

namespace Forwext\Core\Support\Reporting;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Support\Ticket\SupportTicket;
use Forwext\Core\Support\Ticket\SupportTicketRepository;
use Forwext\Core\Support\Ticket\SupportTicketService;

final readonly class SupportReportingService
{
    public const REPORT_PERMISSION='support.report.view';
    public const AUDIT_PERMISSION='support.audit.view';

    public function __construct(
        private SupportTicketRepository $tickets,
        private SupportReportingRepository $reporting,
        private PermissionGate $gate,
    ) {}

    /** @return list<SupportTicket> */
    public function myTickets(int $limit=100):array
    {
        $this->gate->require(PermissionKey::fromString(SupportTicketService::VIEW_OWN_PERMISSION));
        return $this->tickets->forRequester($this->gate->actorId(),$limit);
    }

    public function staffDashboard(?DateTimeImmutable $now=null,int $queueLimit=100):SupportStaffDashboard
    {
        $this->gate->require(PermissionKey::fromString(SupportTicketService::VIEW_ALL_PERMISSION));
        $this->gate->require(PermissionKey::fromString(self::REPORT_PERMISSION));
        $now=self::utc($now);
        $audit=$this->gate->allows(PermissionKey::fromString(self::AUDIT_PERMISSION))
            ? $this->reporting->recentAudit(50)
            : [];
        return new SupportStaffDashboard(
            $this->reporting->summary($now),
            $this->tickets->activeQueue($queueLimit),
            $this->reporting->categoryMetrics($now),
            $audit,
        );
    }

    private static function utc(?DateTimeImmutable $now):DateTimeImmutable
    {
        return ($now??new DateTimeImmutable('now',new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
    }
}
