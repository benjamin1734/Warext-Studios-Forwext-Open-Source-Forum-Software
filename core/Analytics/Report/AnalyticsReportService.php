<?php

declare(strict_types=1);

namespace Forwext\Core\Analytics\Report;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Analytics\Access\AnalyticsAccessService;
use Forwext\Core\Audit\AuditAction;
use Forwext\Core\Audit\AuditEvent;
use Forwext\Core\Audit\AuditRecorder;
use Forwext\Core\Audit\AuditRequestId;
use Forwext\Core\Audit\AuditScope;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class AnalyticsReportService
{
    private const USE_PERMISSION = 'analytics.report.use';
    private const EXPORT_PERMISSION = 'analytics.export';
    private const MANAGE_ALL_PERMISSION = 'analytics.report.manage_all';
    private const UNAGGREGATED_PERMISSION = 'analytics.report.unaggregated';

    public function __construct(
        private DatabaseAnalyticsReportRepository $repository,
        private AnalyticsAccessService $access,
        private AuditRecorder $audit,
    ) {
    }

    /** @return list<AnalyticsReportDataset> */
    public function datasets(EntityId $actor): array
    {
        $this->access->require($actor, self::USE_PERMISSION);

        return array_values(array_filter(
            AnalyticsReportDataset::cases(),
            fn (AnalyticsReportDataset $dataset): bool => $this->access->allows($actor, $dataset->permission()),
        ));
    }

    public function run(EntityId $actor, AnalyticsReportDefinition $definition): AnalyticsReportResult
    {
        $this->access->require($actor, self::USE_PERMISSION);
        $this->access->require($actor, $definition->dataset->permission());

        $minimum = $this->access->allows($actor, self::UNAGGREGATED_PERMISSION)
            ? $definition->privacyMinCount
            : max(5, $definition->privacyMinCount);

        return $this->repository->run($definition->withPrivacyMinCount($minimum));
    }

    public function export(EntityId $actor, AnalyticsReportDefinition $definition): AnalyticsReportResult
    {
        $this->access->require($actor, self::EXPORT_PERMISSION);
        return $this->run($actor, $definition);
    }

    /** @return list<AnalyticsSavedReport> */
    public function savedReports(EntityId $actor): array
    {
        $this->access->require($actor, self::USE_PERMISSION);
        $owner = $this->access->allows($actor, self::MANAGE_ALL_PERMISSION) ? null : $actor;
        $reports = $this->repository->savedReports($owner);

        return array_values(array_filter(
            $reports,
            fn (AnalyticsSavedReport $report): bool => $this->access->allows(
                $actor,
                $report->definition->dataset->permission(),
            ),
        ));
    }

    public function savedReport(EntityId $actor, EntityId $reportId): AnalyticsSavedReport
    {
        $this->access->require($actor, self::USE_PERMISSION);
        $report = $this->repository->savedReport($reportId)
            ?? throw new InvalidArgumentException('Analytics saved report was not found.');

        if ($report->ownerUserId->value() !== $actor->value()) {
            $this->access->require($actor, self::MANAGE_ALL_PERMISSION);
        }
        $this->access->require($actor, $report->definition->dataset->permission());

        return $report;
    }

    public function save(
        EntityId $actor,
        string $name,
        AnalyticsReportDefinition $definition,
        ?EntityId $reportId,
        DateTimeImmutable $now,
        AuditRequestId $requestId,
    ): AnalyticsSavedReport {
        $this->access->require($actor, self::USE_PERMISSION);
        $this->access->require($actor, $definition->dataset->permission());

        $existing = $reportId === null ? null : $this->repository->savedReport($reportId);
        if ($reportId !== null && $existing === null) {
            throw new InvalidArgumentException('Analytics saved report was not found.');
        }
        if ($existing !== null && $existing->ownerUserId->value() !== $actor->value()) {
            $this->access->require($actor, self::MANAGE_ALL_PERMISSION);
        }

        $name = trim($name);
        $at = $now->setTimezone(new DateTimeZone('UTC'));
        $report = new AnalyticsSavedReport(
            $existing?->reportId ?? AnalyticsSavedReport::generateId(),
            $existing?->ownerUserId ?? $actor,
            $name,
            $definition,
            $existing?->createdAt ?? $at,
            $at,
        );

        $event = new AuditEvent(
            AuditEvent::generateId(),
            AuditScope::Administration,
            $actor,
            AuditAction::fromString('analytics.report.save'),
            'analytics.report',
            $report->reportId->value(),
            null,
            'analytics.report.save',
            $requestId,
            self::snapshot($existing),
            self::snapshot($report),
            $at,
        );
        $this->audit->mutate($event, function () use ($report): void {
            $this->repository->save($report);
        });

        return $report;
    }

    public function delete(
        EntityId $actor,
        EntityId $reportId,
        DateTimeImmutable $now,
        AuditRequestId $requestId,
    ): void {
        $report = $this->savedReport($actor, $reportId);
        $at = $now->setTimezone(new DateTimeZone('UTC'));
        $event = new AuditEvent(
            AuditEvent::generateId(),
            AuditScope::Administration,
            $actor,
            AuditAction::fromString('analytics.report.delete'),
            'analytics.report',
            $report->reportId->value(),
            null,
            'analytics.report.delete',
            $requestId,
            self::snapshot($report),
            [],
            $at,
        );

        $deleted = $this->audit->mutate(
            $event,
            fn (): bool => $this->repository->delete($report->reportId),
        );
        if ($deleted !== true) {
            throw new InvalidArgumentException('Analytics saved report was not found.');
        }
    }

    public function canExport(EntityId $actor): bool
    {
        return $this->access->allows($actor, self::EXPORT_PERMISSION);
    }

    public function canUseUnaggregated(EntityId $actor): bool
    {
        return $this->access->allows($actor, self::UNAGGREGATED_PERMISSION);
    }

    public function canManageAll(EntityId $actor): bool
    {
        return $this->access->allows($actor, self::MANAGE_ALL_PERMISSION);
    }

    /** @return array<string,mixed> */
    private static function snapshot(?AnalyticsSavedReport $report): array
    {
        if ($report === null) {
            return [];
        }

        return [
            'owner_user_id'=>$report->ownerUserId->value(),
            'name'=>$report->name,
            'definition'=>$report->definition->toArray(),
        ];
    }
}
