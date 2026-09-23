<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Layout\Builder;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;
use JsonException;

final readonly class DatabaseLayoutBuilderRepository implements LayoutBuilderRepository
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function findByKey(string $key): ?LayoutRecord
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT layout_id,layout_key,draft_revision_id,published_revision_id,created_at_utc,updated_at_utc '
            . 'FROM forwext_ui_layouts WHERE layout_key=:layout_key LIMIT 1',
            ['layout_key' => $key],
        ));

        return $row === null ? null : $this->recordFromRow($row);
    }

    public function revision(EntityId $revisionId): ?LayoutRevision
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT revision_id,layout_id,document_json,checksum_sha256,source,created_by_user_id,created_at_utc '
            . 'FROM forwext_ui_layout_revisions WHERE revision_id=:revision_id LIMIT 1',
            ['revision_id' => $revisionId->value()],
        ));

        if ($row === null) {
            return null;
        }

        $json = $row['document_json'] ?? null;
        $checksum = $row['checksum_sha256'] ?? null;
        if (!is_string($json) || !is_string($checksum) || preg_match('/^[a-f0-9]{64}$/D', $checksum) !== 1) {
            throw new InvalidArgumentException('Stored layout revision JSON is invalid.');
        }
        if (!hash_equals($checksum, hash('sha256', $json))) {
            throw new InvalidArgumentException('Stored layout revision checksum does not match.');
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Stored layout revision JSON is invalid.', previous: $exception);
        }

        if (!is_array($data)) {
            throw new InvalidArgumentException('Stored layout revision document is invalid.');
        }

        return new LayoutRevision(
            EntityId::fromString(self::requiredString($row, 'revision_id')),
            EntityId::fromString(self::requiredString($row, 'layout_id')),
            LayoutDocument::fromArray($data),
            LayoutRevisionSource::from(self::requiredString($row, 'source')),
            EntityId::fromString(self::requiredString($row, 'created_by_user_id')),
            self::date(self::requiredString($row, 'created_at_utc')),
        );
    }

    public function saveDraft(
        LayoutRecord $layout,
        LayoutRevision $revision,
        EntityId $actor,
        DateTimeImmutable $updatedAt,
    ): void {
        $json = json_encode(
            $revision->document->toArray(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        $checksum = hash('sha256', $json);
        $created = self::formatDate($layout->createdAt);
        $updated = self::formatDate($updatedAt);

        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_ui_layouts '
            . '(layout_id,layout_key,draft_revision_id,published_revision_id,created_by_user_id,updated_by_user_id,created_at_utc,updated_at_utc) '
            . 'VALUES (:layout_id,:layout_key,NULL,NULL,:actor,:actor,:created_at,:updated_at) '
            . 'ON DUPLICATE KEY UPDATE updated_by_user_id=VALUES(updated_by_user_id),updated_at_utc=VALUES(updated_at_utc)',
            [
                'layout_id' => $layout->layoutId->value(),
                'layout_key' => $layout->key,
                'actor' => $actor->value(),
                'created_at' => $created,
                'updated_at' => $updated,
            ],
        ));

        $storedLayoutId = $this->database->fetchValue(new CompiledQuery(
            'SELECT layout_id FROM forwext_ui_layouts WHERE layout_key=:layout_key LIMIT 1',
            ['layout_key' => $layout->key],
        ));
        if (!is_string($storedLayoutId) || !hash_equals($layout->layoutId->value(), $storedLayoutId)) {
            throw new InvalidArgumentException('Layout was created concurrently; reload before saving.');
        }

        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_ui_layout_revisions '
            . '(revision_id,layout_id,source,document_json,checksum_sha256,created_by_user_id,created_at_utc) '
            . 'VALUES (:revision_id,:layout_id,:source,:document_json,:checksum,:actor,:created_at)',
            [
                'revision_id' => $revision->revisionId->value(),
                'layout_id' => $layout->layoutId->value(),
                'source' => $revision->source->value,
                'document_json' => $json,
                'checksum' => $checksum,
                'actor' => $actor->value(),
                'created_at' => self::formatDate($revision->createdAt),
            ],
        ));

        $this->database->execute(new CompiledQuery(
            'UPDATE forwext_ui_layouts SET draft_revision_id=:revision_id,updated_by_user_id=:actor,updated_at_utc=:updated_at '
            . 'WHERE layout_id=:layout_id',
            [
                'revision_id' => $revision->revisionId->value(),
                'actor' => $actor->value(),
                'updated_at' => $updated,
                'layout_id' => $layout->layoutId->value(),
            ],
        ));
    }

    public function publish(
        EntityId $layoutId,
        EntityId $expectedDraftRevisionId,
        EntityId $actor,
        DateTimeImmutable $updatedAt,
    ): bool {
        return $this->database->execute(new CompiledQuery(
            'UPDATE forwext_ui_layouts SET published_revision_id=draft_revision_id,updated_by_user_id=:actor,updated_at_utc=:updated_at '
            . 'WHERE layout_id=:layout_id AND draft_revision_id=:expected_revision',
            [
                'actor' => $actor->value(),
                'updated_at' => self::formatDate($updatedAt),
                'layout_id' => $layoutId->value(),
                'expected_revision' => $expectedDraftRevisionId->value(),
            ],
        )) === 1;
    }

    /** @param array<string,mixed> $row */
    private function recordFromRow(array $row): LayoutRecord
    {
        return new LayoutRecord(
            EntityId::fromString(self::requiredString($row, 'layout_id')),
            self::requiredString($row, 'layout_key'),
            self::nullableId($row['draft_revision_id'] ?? null),
            self::nullableId($row['published_revision_id'] ?? null),
            self::date(self::requiredString($row, 'created_at_utc')),
            self::date(self::requiredString($row, 'updated_at_utc')),
        );
    }

    private static function nullableId(mixed $value): ?EntityId
    {
        return $value === null ? null : EntityId::fromString((string) $value);
    }

    /** @param array<string,mixed> $row */
    private static function requiredString(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException('Stored layout row is invalid.');
        }
        return $value;
    }

    private static function date(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (!$date instanceof DateTimeImmutable) {
            throw new InvalidArgumentException('Stored layout date is invalid.');
        }
        return $date;
    }

    private static function formatDate(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
