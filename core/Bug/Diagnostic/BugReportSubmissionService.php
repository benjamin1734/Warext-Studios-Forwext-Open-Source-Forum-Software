<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Diagnostic;

use Closure;
use DateTimeImmutable;
use Forwext\Core\Bug\Report\BugReportService;
use Forwext\Core\Bug\Report\BugReportSeverity;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Http\Request;

final readonly class BugReportSubmissionService
{
    public function __construct(
        private TransactionalQueryExecutor $database,
        private BugReportService $reports,
        private BugDiagnosticContextCollector $collector,
        private BugDiagnosticContextRepository $diagnostics,
    ) {
    }

    public function create(
        Request $request,
        string $categoryKey,
        string $title,
        string $summary,
        ?BugReportSeverity $severity = null,
        ?DateTimeImmutable $now = null,
    ): BugReportSubmissionReceipt {
        return $this->atomic(function () use (
            $request,
            $categoryKey,
            $title,
            $summary,
            $severity,
            $now,
        ): BugReportSubmissionReceipt {
            $report = $this->reports->create($categoryKey, $title, $summary, $severity, $now);
            $context = $this->collector->collect(
                $request,
                $report->reportId,
                $report->reporterUserId,
                $now,
            );
            $this->diagnostics->save($context);
            return new BugReportSubmissionReceipt($report, $context);
        });
    }

    private function atomic(Closure $callback): mixed
    {
        return $this->database->inTransaction()
            ? $callback()
            : $this->database->transaction(static fn () => $callback());
    }
}
