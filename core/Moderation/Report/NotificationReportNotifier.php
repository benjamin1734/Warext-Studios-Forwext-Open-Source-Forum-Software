<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Report;

use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Notification\NotificationDefinition;
use Forwext\Core\Notification\NotificationDispatcher;
use Forwext\Core\Notification\NotificationRegistry;
use Forwext\Core\Notification\NotificationRequest;

final readonly class NotificationReportNotifier implements ReportNotifier
{
    public const RECEIVED_TYPE = 'moderation.report.received';
    public const ASSIGNED_TYPE = 'moderation.report.assigned';
    public const STATUS_TYPE = 'moderation.report.status';

    public function __construct(private NotificationDispatcher $dispatcher)
    {
    }

    public static function registerDefinitions(NotificationRegistry $registry): void
    {
        $registry->register(new NotificationDefinition(
            self::RECEIVED_TYPE,
            'reports',
            'Raporunuz alındı',
            '{{target}} için raporunuz inceleme sırasına alındı.',
        ));
        $registry->register(new NotificationDefinition(
            self::ASSIGNED_TYPE,
            'reports',
            'Moderasyon raporu size atandı',
            '{{target}} için açılan rapor grubu size atandı.',
        ));
        $registry->register(new NotificationDefinition(
            self::STATUS_TYPE,
            'reports',
            'Rapor durumu güncellendi',
            '{{target}} için raporunuzun durumu: {{status}}.',
        ));
    }

    public function received(EntityId $reporterUserId, ReportReceipt $receipt, ReportableContent $content): void
    {
        $this->dispatcher->dispatch(new NotificationRequest(
            $reporterUserId,
            self::RECEIVED_TYPE,
            ['target' => $content->title],
            null,
            'report-received:' . $receipt->reportId->value(),
            '/account/reports',
            ['report_id' => $receipt->reportId->value(), 'group_id' => $receipt->groupId->value()],
        ));
    }

    public function assigned(EntityId $moderatorUserId, ReportGroup $group): void
    {
        $this->dispatcher->dispatch(new NotificationRequest(
            $moderatorUserId,
            self::ASSIGNED_TYPE,
            ['target' => $group->targetTitle],
            null,
            'report-assigned:' . $group->groupId->value() . ':' . $moderatorUserId->value(),
            '/moderation/reports/' . rawurlencode($group->groupId->value()),
            ['group_id' => $group->groupId->value()],
        ));
    }

    public function statusChanged(array $reporterUserIds, ReportGroup $group): void
    {
        foreach ($reporterUserIds as $reporterUserId) {
            $this->dispatcher->dispatch(new NotificationRequest(
                $reporterUserId,
                self::STATUS_TYPE,
                ['target' => $group->targetTitle, 'status' => $group->status->label()],
                null,
                'report-status:' . $group->groupId->value() . ':' . $group->status->value . ':' . $reporterUserId->value(),
                '/account/reports',
                ['group_id' => $group->groupId->value(), 'status' => $group->status->value],
            ));
        }
    }
}
