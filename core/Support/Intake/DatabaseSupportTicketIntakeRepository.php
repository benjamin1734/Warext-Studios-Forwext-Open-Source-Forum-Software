<?php

declare(strict_types=1);

namespace Forwext\Core\Support\Intake;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Forum\Attachment\AttachmentFilename;
use Forwext\Core\Support\Ticket\SupportCategory;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use ValueError;

final readonly class DatabaseSupportTicketIntakeRepository implements SupportTicketIntakeRepository
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function activeFields(string $categoryKey): array
    {
        SupportCategory::assertKey($categoryKey);
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT category_key,field_key,label,field_type,required,choices_json,max_length,help_text,sort_order,active '
            . 'FROM forwext_support_category_fields WHERE category_key=:category_key AND active=1 '
            . 'ORDER BY sort_order,field_key',
            ['category_key'=>$categoryKey],
        ));
        return array_map($this->hydrateDefinition(...), $rows);
    }

    public function saveFieldDefinition(SupportFieldDefinition $definition): void
    {
        try {
            $choices = json_encode(
                $definition->choices,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('Support field choices cannot be encoded.', previous: $exception);
        }

        $affected = $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_support_category_fields '
            . '(category_key,field_key,label,field_type,required,choices_json,max_length,help_text,sort_order,active,'
            . 'created_at_utc,updated_at_utc) '
            . 'VALUES (:category_key,:field_key,:label,:field_type,:required,:choices_json,:max_length,:help_text,'
            . ':sort_order,:active,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) '
            . 'ON DUPLICATE KEY UPDATE label=VALUES(label),field_type=VALUES(field_type),required=VALUES(required),'
            . 'choices_json=VALUES(choices_json),max_length=VALUES(max_length),help_text=VALUES(help_text),'
            . 'sort_order=VALUES(sort_order),active=VALUES(active),updated_at_utc=VALUES(updated_at_utc)',
            [
                'category_key'=>$definition->categoryKey,
                'field_key'=>$definition->fieldKey,
                'label'=>$definition->label,
                'field_type'=>$definition->type->value,
                'required'=>$definition->required,
                'choices_json'=>$choices,
                'max_length'=>$definition->maxLength,
                'help_text'=>$definition->helpText,
                'sort_order'=>$definition->sortOrder,
                'active'=>$definition->active,
            ],
        ));
        if ($affected > 2) {
            throw new RuntimeException('Support field definition mutation affected an invalid row count.');
        }
    }

    public function saveIntake(EntityId $ticketId, string $description): void
    {
        $description = trim($description);
        if ($description === '' || strlen($description) > 10000) {
            throw new InvalidArgumentException('Support description must contain 1-10000 UTF-8 bytes.');
        }
        $affected = $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_support_ticket_intake (ticket_id,description,created_at_utc) '
            . 'VALUES (:ticket_id,:description,UTC_TIMESTAMP(6))',
            ['ticket_id'=>$ticketId->value(),'description'=>$description],
            true,
        ));
        if ($affected !== 1) {
            throw new RuntimeException('Support ticket intake was not persisted.');
        }
    }

    public function description(EntityId $ticketId): ?string
    {
        $value = $this->database->fetchValue(new CompiledQuery(
            'SELECT description FROM forwext_support_ticket_intake WHERE ticket_id=:ticket_id LIMIT 1',
            ['ticket_id'=>$ticketId->value()],
        ));
        return is_string($value) ? $value : null;
    }

    public function saveFieldValues(EntityId $ticketId, array $values): void
    {
        foreach ($values as $fieldKey => $value) {
            if (!is_string($fieldKey) || !$value instanceof SupportFieldValue) {
                throw new InvalidArgumentException('Support field values must be typed.');
            }
            $affected = $this->database->execute(new CompiledQuery(
                'INSERT INTO forwext_support_ticket_field_values (ticket_id,field_key,field_type,value_json) '
                . 'VALUES (:ticket_id,:field_key,:field_type,:value_json)',
                [
                    'ticket_id'=>$ticketId->value(),
                    'field_key'=>$fieldKey,
                    'field_type'=>$value->type->value,
                    'value_json'=>$value->encoded(),
                ],
                true,
            ));
            if ($affected !== 1) {
                throw new RuntimeException('Support field value was not persisted.');
            }
        }
    }

    public function saveContext(EntityId $ticketId, SupportContextLink $context): void
    {
        $affected = $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_support_ticket_context_links '
            . '(ticket_id,context_type,target_id,label_snapshot,created_at_utc) '
            . 'VALUES (:ticket_id,:context_type,:target_id,:label_snapshot,UTC_TIMESTAMP(6))',
            [
                'ticket_id'=>$ticketId->value(),
                'context_type'=>$context->type->value,
                'target_id'=>$context->targetId->value(),
                'label_snapshot'=>$context->labelSnapshot,
            ],
            true,
        ));
        if ($affected !== 1) {
            throw new RuntimeException('Support context link was not persisted.');
        }
    }

    public function saveAttachment(SupportAttachmentRecord $attachment): void
    {
        $affected = $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_support_ticket_attachments '
            . '(attachment_id,ticket_id,owner_user_id,filename,media_type,extension,size_bytes,sha256,storage_path,'
            . 'image_width,image_height,metadata_stripped,created_at_utc) '
            . 'VALUES (:attachment_id,:ticket_id,:owner_user_id,:filename,:media_type,:extension,:size_bytes,:sha256,'
            . ':storage_path,:image_width,:image_height,:metadata_stripped,:created_at)',
            [
                'attachment_id'=>$attachment->attachmentId->value(),
                'ticket_id'=>$attachment->ticketId->value(),
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
                'created_at'=>$attachment->createdAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
            ],
            true,
        ));
        if ($affected !== 1) {
            throw new RuntimeException('Support attachment metadata was not persisted.');
        }
    }

    public function fieldValues(EntityId $ticketId): array
    {
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT field_key,field_type,value_json FROM forwext_support_ticket_field_values '
            . 'WHERE ticket_id=:ticket_id ORDER BY field_key',
            ['ticket_id'=>$ticketId->value()],
        ));
        $values = [];
        foreach ($rows as $row) {
            try {
                $type = SupportFieldType::from((string) $row['field_type']);
            } catch (ValueError $exception) {
                throw new RuntimeException('Stored support field type is invalid.', previous: $exception);
            }
            $key = (string) $row['field_key'];
            $values[$key] = SupportFieldValue::fromStored($type, (string) $row['value_json']);
        }
        return $values;
    }

    public function context(EntityId $ticketId): ?SupportContextLink
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT context_type,target_id,label_snapshot FROM forwext_support_ticket_context_links '
            . 'WHERE ticket_id=:ticket_id LIMIT 1',
            ['ticket_id'=>$ticketId->value()],
        ));
        if ($row === null) {
            return null;
        }
        try {
            $type = SupportContextType::from((string) $row['context_type']);
        } catch (ValueError $exception) {
            throw new RuntimeException('Stored support context type is invalid.', previous: $exception);
        }
        return new SupportContextLink(
            $type,
            EntityId::fromString((string) $row['target_id']),
            (string) $row['label_snapshot'],
        );
    }

    public function attachments(EntityId $ticketId): array
    {
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT attachment_id,ticket_id,owner_user_id,filename,media_type,extension,size_bytes,sha256,storage_path,'
            . 'image_width,image_height,metadata_stripped,created_at_utc '
            . 'FROM forwext_support_ticket_attachments WHERE ticket_id=:ticket_id '
            . 'ORDER BY created_at_utc,attachment_id',
            ['ticket_id'=>$ticketId->value()],
        ));
        return array_map($this->hydrateAttachment(...), $rows);
    }

    /** @param array<string,mixed> $row */
    private function hydrateDefinition(array $row): SupportFieldDefinition
    {
        try {
            $type = SupportFieldType::from((string) $row['field_type']);
            $choices = json_decode((string) $row['choices_json'], true, 16, JSON_THROW_ON_ERROR);
        } catch (ValueError|JsonException $exception) {
            throw new RuntimeException('Stored support field definition is invalid.', previous: $exception);
        }
        if (!is_array($choices)) {
            throw new RuntimeException('Stored support field choices are invalid.');
        }

        return new SupportFieldDefinition(
            (string) $row['category_key'],
            (string) $row['field_key'],
            (string) $row['label'],
            $type,
            (bool) $row['required'],
            $choices,
            (int) $row['max_length'],
            (string) ($row['help_text'] ?? ''),
            (int) $row['sort_order'],
            (bool) $row['active'],
        );
    }

    /** @param array<string,mixed> $row */
    private function hydrateAttachment(array $row): SupportAttachmentRecord
    {
        return new SupportAttachmentRecord(
            EntityId::fromString((string) $row['attachment_id']),
            EntityId::fromString((string) $row['ticket_id']),
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

    private static function parse(string $value): DateTimeImmutable
    {
        $time = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (!$time instanceof DateTimeImmutable) {
            throw new RuntimeException('Stored support intake timestamp is invalid.');
        }
        return $time;
    }
}
