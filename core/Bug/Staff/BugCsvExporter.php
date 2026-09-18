<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Staff;

use Forwext\Core\Bug\Report\BugReport;
use RuntimeException;

final readonly class BugCsvExporter
{
    /**
     * @param list<BugReport> $reports
     */
    public function export(array $reports, BugStaffRepository $repository): string
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('Bug CSV export stream could not be created.');
        }

        try {
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, [
                'report_id','title','category','status','severity',
                'reporter_user_id','assigned_user_id','duplicate_of',
                'created_at_utc','updated_at_utc',
            ]);

            foreach ($reports as $report) {
                $duplicate = $repository->duplicateLink($report->reportId);
                fputcsv($stream, [
                    $this->cell($report->reportId->value()),
                    $this->cell($report->title),
                    $this->cell($report->categoryKey),
                    $this->cell($report->status->value),
                    $this->cell($report->severity->value),
                    $this->cell($report->reporterUserId?->value() ?? ''),
                    $this->cell($report->assignedUserId?->value() ?? ''),
                    $this->cell($duplicate?->canonicalReportId->value() ?? ''),
                    $this->cell($report->createdAt->format('Y-m-d H:i:s.u')),
                    $this->cell($report->updatedAt->format('Y-m-d H:i:s.u')),
                ]);
            }

            rewind($stream);
            $csv = stream_get_contents($stream);
            if (!is_string($csv)) {
                throw new RuntimeException('Bug CSV export could not be read.');
            }
            return $csv;
        } finally {
            fclose($stream);
        }
    }

    private function cell(string $value): string
    {
        $trimmed = ltrim($value);
        if ($trimmed !== '' && in_array($trimmed[0], ['=','+','-','@'], true)) {
            return "'" . $value;
        }
        return $value;
    }
}
