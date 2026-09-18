<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Conversation;

use Forwext\Core\Bug\Report\BugReport;
use Forwext\Core\Notification\NotificationDefinition;
use Forwext\Core\Notification\NotificationDispatcher;
use Forwext\Core\Notification\NotificationRegistry;
use Forwext\Core\Notification\NotificationRequest;

final readonly class NotificationBugReportNotifier implements BugReportNotifier
{
    public const STAFF_REPLY='bug.report.staff_reply';
    public const REPORTER_REPLY='bug.report.reporter_reply';
    public const STATUS='bug.report.status';

    public function __construct(private NotificationDispatcher $dispatcher)
    {
    }

    public static function registerDefinitions(NotificationRegistry $registry): void
    {
        $registry->register(new NotificationDefinition(
            self::STAFF_REPLY,'bug','Hata bildiriminize yanıt geldi',
            '{{title}} başlıklı hata bildiriminize yeni bir yetkili yanıtı geldi.',
        ));
        $registry->register(new NotificationDefinition(
            self::REPORTER_REPLY,'bug','Hata bildirimine ek bilgi geldi',
            '{{title}} başlıklı hata bildirimine kullanıcı yeni bilgi ekledi.',
        ));
        $registry->register(new NotificationDefinition(
            self::STATUS,'bug','Hata bildirimi durumu güncellendi',
            '{{title}} başlıklı hata bildiriminizin durumu: {{status}}.',
        ));
    }

    public function staffReply(BugReport $report,BugReportMessage $message): void
    {
        if($report->reporterUserId===null)return;
        $this->dispatcher->dispatch(new NotificationRequest(
            $report->reporterUserId,self::STAFF_REPLY,['title'=>$report->title],
            'bug-report:'.$report->reportId->value(),
            'bug-staff-reply:'.$message->messageId->value(),
            '/bugs/'.$report->reportId->value(),
            ['report_id'=>$report->reportId->value(),'message_id'=>$message->messageId->value()],
        ));
    }

    public function reporterReply(BugReport $report,BugReportMessage $message): void
    {
        if($report->assignedUserId===null)return;
        $this->dispatcher->dispatch(new NotificationRequest(
            $report->assignedUserId,self::REPORTER_REPLY,['title'=>$report->title],
            'bug-report:'.$report->reportId->value(),
            'bug-reporter-reply:'.$message->messageId->value(),
            '/bugs/'.$report->reportId->value(),
            ['report_id'=>$report->reportId->value(),'message_id'=>$message->messageId->value()],
        ));
    }

    public function statusChanged(BugReport $report): void
    {
        if($report->reporterUserId===null)return;
        $this->dispatcher->dispatch(new NotificationRequest(
            $report->reporterUserId,self::STATUS,
            ['title'=>$report->title,'status'=>$report->status->label()],
            'bug-report:'.$report->reportId->value(),
            'bug-status:'.$report->reportId->value().':'.$report->status->value.':'.$report->version,
            '/bugs/'.$report->reportId->value(),
            ['report_id'=>$report->reportId->value(),'status'=>$report->status->value],
        ));
    }
}
