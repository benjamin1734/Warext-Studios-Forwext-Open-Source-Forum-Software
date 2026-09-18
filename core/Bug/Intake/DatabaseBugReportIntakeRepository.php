<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Intake;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Forum\Attachment\AttachmentFilename;
use RuntimeException;

final readonly class DatabaseBugReportIntakeRepository implements BugReportIntakeRepository
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function saveDetails(BugReportDetails $details): void
    {
        $affected = $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_bug_report_details '
            . '(report_id,reproduction_steps,expected_result,actual_result,source_path,created_at_utc) '
            . 'VALUES (:report_id,:reproduction_steps,:expected_result,:actual_result,:source_path,:created_at)',
            [
                'report_id'=>$details->reportId->value(),
                'reproduction_steps'=>$details->reproductionSteps,
                'expected_result'=>$details->expectedResult,
                'actual_result'=>$details->actualResult,
                'source_path'=>$details->sourcePath,
                'created_at'=>self::format($details->createdAt),
            ],
            true,
        ));
        if ($affected !== 1) {
            throw new RuntimeException('Bug report details were not persisted.');
        }
    }

    public function details(EntityId $reportId): ?BugReportDetails
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT report_id,reproduction_steps,expected_result,actual_result,source_path,created_at_utc '
            . 'FROM forwext_bug_report_details WHERE report_id=:report_id LIMIT 1',
            ['report_id'=>$reportId->value()],
        ));
        if ($row === null) {
            return null;
        }

        return new BugReportDetails(
            EntityId::fromString((string) $row['report_id']),
            (string) $row['reproduction_steps'],
            (string) $row['expected_result'],
            (string) $row['actual_result'],
            $row['source_path'] === null ? null : (string) $row['source_path'],
            self::parse((string) $row['created_at_utc']),
        );
    }

    public function saveAttachment(BugAttachmentRecord $attachment): void
    {
        $affected = $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_bug_report_attachments '
            . '(attachment_id,report_id,owner_user_id,filename,media_type,extension,size_bytes,sha256,storage_path,'
            . 'image_width,image_height,metadata_stripped,created_at_utc) '
            . 'VALUES (:attachment_id,:report_id,:owner_user_id,:filename,:media_type,:extension,:size_bytes,:sha256,'
            . ':storage_path,:image_width,:image_height,:metadata_stripped,:created_at)',
            [
                'attachment_id'=>$attachment->attachmentId->value(),
                'report_id'=>$attachment->reportId->value(),
                'owner_user_id'=>$attachment->ownerUserId?->value(),
                'filename'=>$attachment->filename->value(),
                'media_type'=>$attachment->mediaType,
                'extension'=>$attachment->extension,
                'size_bytes'=>$attachment->sizeBytes,
                'sha256'=>$attachment->sha256,
                'storage_path'=>$attachment->storagePath,
                'image_width'=>$attachment->imageWidth,
                'image_height'=>$attachment->imageHeight,
                'metadata_stripped'=>$attachment->metadataStripped,
                'created_at'=>self::format($attachment->createdAt),
            ],
            true,
        ));
        if ($affected !== 1) {
            throw new RuntimeException('Bug attachment metadata was not persisted.');
        }
    }

    public function attachments(EntityId $reportId): array
    {
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT attachment_id,report_id,owner_user_id,filename,media_type,extension,size_bytes,sha256,storage_path,'
            . 'image_width,image_height,metadata_stripped,created_at_utc '
            . 'FROM forwext_bug_report_attachments WHERE report_id=:report_id '
            . 'ORDER BY created_at_utc,attachment_id',
            ['report_id'=>$reportId->value()],
        ));

        return array_map($this->hydrateAttachment(...), $rows);
    }

    /** @param array<string,mixed> $row */
    private function hydrateAttachment(array $row): BugAttachmentRecord
    {
        return new BugAttachmentRecord(
            EntityId::fromString((string) $row['attachment_id']),
            EntityId::fromString((string) $row['report_id']),
            $row['owner_user_id'] === null ? null : UserId::fromStored((string) $row['owner_user_id']),
            AttachmentFilename::fromClient((string) $row['filename']),
            (string) $row['media_type'],
            (string) $row['extension'],
            (int) $row['size_bytes'],
            (string) $row['sha256'],
            (string) $row['storage_path'],
            $row['image_width'] === null ? null : (int) $row['image_width'],
            $row['image_height'] === null ? null : (int) $row['image_height'],
            (bool) $row['metadata_stripped'],
            self::parse((string) $row['created_at_utc']),
        );
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function parse(string $value): DateTimeImmutable
    {
        $time = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (!$time instanceof DateTimeImmutable) {
            throw new RuntimeException('Stored bug intake timestamp is invalid.');
        }
        return $time;
    }
}
