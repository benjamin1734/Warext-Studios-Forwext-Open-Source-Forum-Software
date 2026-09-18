<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Intake;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Bug\Diagnostic\BugReportSubmissionService;
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
        private BugReportSubmissionService $reports,
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
        ?string $sourcePath = null,
        array $uploads = [],
        ?DateTimeImmutable $now = null,
    ): BugReportFormSubmissionReceipt {
        $reproductionSteps = $this->requiredText(
            $reproductionSteps,
            $this->policy->reproductionMaxBytes,
            'Bug reproduction steps',
        );
        $expectedResult = $this->requiredText(
            $expectedResult,
            $this->policy->expectedMaxBytes,
            'Bug expected result',
        );
        $actualResult = $this->requiredText(
            $actualResult,
            $this->policy->actualMaxBytes,
            'Bug actual result',
        );
        $sourcePath = $this->sourcePath($sourcePath);

        if (count($uploads) > $this->policy->maxAttachments) {
            throw new InvalidArgumentException('Bug attachment count exceeds the configured limit.');
        }
        foreach ($uploads as $upload) {
            if (!$upload instanceof BugUpload) {
                throw new InvalidArgumentException('Bug uploads must be typed.');
            }
        }

        /** @var list<array{upload:BugUpload,inspection:AttachmentInspection}> $prepared */
        $prepared = [];
        foreach ($uploads as $upload) {
            $prepared[] = [
                'upload'=>$upload,
                'inspection'=>$this->inspector->inspect($upload->contents),
            ];
        }

        $at = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));

        /** @var list<StoragePath> $written */
        $written = [];
        try {
            return $this->database->transaction(function () use (
                $request,
                $categoryKey,
                $title,
                $summary,
                $reproductionSteps,
                $expectedResult,
                $actualResult,
                $sourcePath,
                $prepared,
                $at,
                &$written,
            ): BugReportFormSubmissionReceipt {
                $submission = $this->reports->create(
                    $request,
                    $categoryKey,
                    $title,
                    $summary,
                    null,
                    $at,
                );

                $details = new BugReportDetails(
                    $submission->report->reportId,
                    $reproductionSteps,
                    $expectedResult,
                    $actualResult,
                    $sourcePath,
                    $at,
                );
                $this->intake->saveDetails($details);

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

                    $record = new BugAttachmentRecord(
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
                    $this->intake->saveAttachment($record);
                    $attachments[] = $record;
                }

                return new BugReportFormSubmissionReceipt(
                    $submission->report,
                    $submission->diagnosticContext,
                    $details,
                    $attachments,
                );
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

    private function requiredText(string $value, int $maxBytes, string $label): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > $maxBytes) {
            throw new InvalidArgumentException($label . ' is outside the supported length.');
        }
        return $value;
    }

    private function sourcePath(?string $sourcePath): ?string
    {
        if ($sourcePath === null || trim($sourcePath) === '') {
            return null;
        }
        $sourcePath = trim($sourcePath);
        $sourcePath = explode('#', $sourcePath, 2)[0];
        $sourcePath = explode('?', $sourcePath, 2)[0];

        if (
            $sourcePath === ''
            || strlen($sourcePath) > $this->policy->sourcePathMaxBytes
            || !str_starts_with($sourcePath, '/')
            || str_starts_with($sourcePath, '//')
            || preg_match('/[\x00-\x1F\x7F]/', $sourcePath) === 1
        ) {
            throw new InvalidArgumentException('Bug source path is invalid.');
        }
        return $sourcePath;
    }
}
