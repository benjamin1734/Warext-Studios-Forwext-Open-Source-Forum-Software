<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Report;

use Forwext\Core\Notification\NotificationDefinition;
use Forwext\Core\Notification\NotificationDispatcher;
use Forwext\Core\Notification\NotificationRegistry;
use Forwext\Core\Notification\NotificationRequest;

final readonly class NotificationBugReportNotifier implements BugReportNotifier
{
    public const STAFF_RESPONSE = 'bug.report.staff_response';
    public const STATUS = 'bug.report.status';
    public const REPORTER_INFO = 'bug.report.reporter_info';

    public function __construct(private NotificationDispatcher $dispatcher)
    {
    }

    public static function registerDefinitions(NotificationRegistry $registry): void
    {
        $registry->register(new NotificationDefinition(
            self::STAFF_RESPONSE,
            'bug',
            'Hata bildiriminize yanıt geldi',
            '{{title}} başlıklı hata bildiriminize yeni bir yetkili yanıtı geldi.',
        ));
        $registry->register(new NotificationDefinition(
            self::STATUS,
            'bug',
            'Hata bildirimi durumu güncellendi',
            '{{title}} başlıklı hata bildiriminizin durumu: {{status}}.',
        ));
        $registry->register(new NotificationDefinition(
            self::REPORTER_INFO,
            'bug',
            'Hata bildirimine ek bilgi geldi',
            '{{title}} başlıklı hata bildirimine kullanıcı yeni bilgi ekledi.',
        ));
    }

    public function staffResponse(BugReport $report, BugReportHistoryEntry $entry): void
    {
        if ($report->reporterUserId === null
            || ($entry->actorUserId !== null && $report->reporterUserId->equals($entry->actorUserId))
        ) {
            return;
        }

        $this->dispatcher->dispatch(new NotificationRequest(
            $report->reporterUserId,
            self::STAFF_RESPONSE,
            ['title'=>$report->title],
            'bug-report:' . $report->reportId->value(),
            'bug-staff-response:' . $entry->historyId->value(),
            '/bugs/' . rawurlencode($report->reportId->value()),
            ['report_id'=>$report->reportId->value(),'history_id'=>$entry->historyId->value()],
        ));
    }

    public function statusChanged(BugReport $report): void
    {
        if ($report->reporterUserId === null) {
            return;
        }

        $this->dispatcher->dispatch(new NotificationRequest(
            $report->reporterUserId,
            self::STATUS,
            ['title'=>$report->title,'status'=>$report->status->label()],
            'bug-report:' . $report->reportId->value(),
            'bug-status:' . $report->reportId->value() . ':' . $report->status->value . ':' . $report->version,
            '/bugs/' . rawurlencode($report->reportId->value()),
            ['report_id'=>$report->reportId->value(),'status'=>$report->status->value],
        ));
    }

    public function reporterInfoAdded(BugReport $report, BugReportHistoryEntry $entry): void
    {
        if ($report->assignedUserId === null
            || ($entry->actorUserId !== null && $report->assignedUserId->equals($entry->actorUserId))
        ) {
            return;
        }

        $this->dispatcher->dispatch(new NotificationRequest(
            $report->assignedUserId,
            self::REPORTER_INFO,
            ['title'=>$report->title],
            'bug-report:' . $report->reportId->value(),
            'bug-reporter-info:' . $entry->historyId->value(),
            '/bugs/' . rawurlencode($report->reportId->value()),
            ['report_id'=>$report->reportId->value(),'history_id'=>$entry->historyId->value()],
        ));
    }
}
