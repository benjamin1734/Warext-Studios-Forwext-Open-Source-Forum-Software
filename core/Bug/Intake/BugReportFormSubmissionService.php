<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Intake;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Bug\Diagnostic\BugReportSubmissionService;
use Forwext\Core\Bug\Report\BugReportSeverity;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Forum\Attachment\AttachmentInspection;
use Forwext\Core\Forum\Attachment\AttachmentInspector;
use Forwext\Core\Http\Request;
use Forwext\Core\Storage\StorageDriver;
use Forwext\Core\Storage\StoragePath;
use Forwext\Core\Storage\StorageVisibility;
use InvalidArgumentException;
use Throwable;

final readonly class BugReportFormSubmissionService
{
    public function __construct(
        private TransactionalQueryExecutor $database,
        private BugReportSubmissionService $submission,
        private BugReportIntakeRepository $intake,
        private StorageDriver $storage,
        private AttachmentInspector $inspector,
        private BugReportFormPolicy $policy = new BugReportFormPolicy(),
    ) {
    }

    /**
     * @param list<BugUpload> $uploads
     */
    public function submit(
        Request $request,
        string $categoryKey,
        string $title,
        string $summary,
        string $reproductionSteps,
        string $expectedResult,
        string $actualResult,
        ?string $reportedSourcePath,
        array $uploads = [],
        ?BugReportSeverity $severity = null,
        ?DateTimeImmutable $now = null,
    ): BugReportFormReceipt {
        if (count($uploads) > $this->policy->maxAttachments) {
            throw new InvalidArgumentException('Bug report attachment count exceeds the configured limit.');
        }
        foreach ($uploads as $upload) {
            if (!$upload instanceof BugUpload) {
                throw new InvalidArgumentException('Bug report uploads must be typed.');
            }
        }

        $reproductionSteps = self::field($reproductionSteps, 'reproduction steps', $this->policy->maxFieldBytes);
        $expectedResult = self::field($expectedResult, 'expected result', $this->policy->maxFieldBytes);
        $actualResult = self::field($actualResult, 'actual result', $this->policy->maxFieldBytes);
        $reportedSourcePath = self::sourcePath($reportedSourcePath);
        $at = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));

        /** @var list<array{upload:BugUpload,inspection:AttachmentInspection}> $prepared */
        $prepared = [];
        foreach ($uploads as $upload) {
            $prepared[] = [
                'upload'=>$upload,
                'inspection'=>$this->inspector->inspect($upload->contents),
            ];
        }

        /** @var list<StoragePath> $written */
        $written = [];
        try {
            return $this->atomic(function () use (
                $request,
                $categoryKey,
                $title,
                $summary,
                $reproductionSteps,
                $expectedResult,
                $actualResult,
                $reportedSourcePath,
                $prepared,
                $severity,
                $at,
                &$written,
            ): BugReportFormReceipt {
                $submission = $this->submission->create(
                    $request,
                    $categoryKey,
                    trim($title),
                    trim($summary),
                    $severity,
                    $at,
                );

                $record = new BugReportIntake(
                    $submission->report->reportId,
                    $reproductionSteps,
                    $expectedResult,
                    $actualResult,
                    $reportedSourcePath,
                    $at,
                );
                $this->intake->saveIntake($record);

                $attachments = [];
                foreach ($prepared as $item) {
                    $attachmentId = BugAttachmentRecord::generateId();
                    $inspection = $item['inspection'];
                    $path = StoragePath::fromString(sprintf(
                        'bugs/reports/%s/%s/%s.%s',
                        $submission->report->reportId->value(),
                        $attachmentId->value(),
                        $inspection->sha256,
                        $inspection->extension,
                    ));
                    $this->storage->put(
                        $path,
                        $inspection->contents,
                        StorageVisibility::Private,
                        $inspection->mediaType,
                    );
                    $written[] = $path;

                    $attachment = new BugAttachmentRecord(
                        $attachmentId,
                        $submission->report->reportId,
                        $submission->report->reporterUserId,
                        $item['upload']->filename,
                        $inspection->mediaType,
                        $inspection->extension,
                        $inspection->sizeBytes,
                        $inspection->sha256,
                        $path->value(),
                        $inspection->imageWidth,
                        $inspection->imageHeight,
                        $inspection->metadataStripped,
                        $at,
                    );
                    $this->intake->saveAttachment($attachment);
                    $attachments[] = $attachment;
                }

                return new BugReportFormReceipt($submission, $record, $attachments);
            });
        } catch (Throwable $exception) {
            foreach (array_reverse($written) as $path) {
                try {
                    $this->storage->delete($path, StorageVisibility::Private);
                } catch (Throwable) {
                }
            }
            throw $exception;
        }
    }

    private function atomic(Closure $callback): mixed
    {
        return $this->database->inTransaction()
            ? $callback()
            : $this->database->transaction(static fn () => $callback());
    }

    private static function field(string $value, string $label, int $maxBytes): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > $maxBytes) {
            throw new InvalidArgumentException('Bug report ' . $label . ' is outside the supported length.');
        }
        return $value;
    }

    private static function sourcePath(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $path = trim($value);
        $path = explode('?', $path, 2)[0];
        $path = explode('#', $path, 2)[0];
        return $path;
    }
}
