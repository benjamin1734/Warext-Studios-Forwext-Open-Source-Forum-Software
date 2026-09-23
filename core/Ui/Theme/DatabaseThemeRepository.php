<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Theme;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;
use JsonException;

final readonly class DatabaseThemeRepository implements ThemeRepository
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function all(): array
    {
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT theme_id,theme_key,name,parent_theme_id,staging_revision_id,published_revision_id,created_at_utc,updated_at_utc '
            . 'FROM forwext_themes ORDER BY theme_key ASC',
        ));

        return array_map(fn (array $row): ThemeDefinition => $this->definitionFromRow($row), $rows);
    }

    public function findByKey(string $key): ?ThemeDefinition
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT theme_id,theme_key,name,parent_theme_id,staging_revision_id,published_revision_id,created_at_utc,updated_at_utc '
            . 'FROM forwext_themes WHERE theme_key=:theme_key LIMIT 1',
            ['theme_key' => $key],
        ));

        return $row === null ? null : $this->definitionFromRow($row);
    }

    public function findById(EntityId $themeId): ?ThemeDefinition
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT theme_id,theme_key,name,parent_theme_id,staging_revision_id,published_revision_id,created_at_utc,updated_at_utc '
            . 'FROM forwext_themes WHERE theme_id=:theme_id LIMIT 1',
            ['theme_id' => $themeId->value()],
        ));

        return $row === null ? null : $this->definitionFromRow($row);
    }

    public function revision(EntityId $revisionId): ?ThemeRevision
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT revision_id,theme_id,payload_json,checksum_sha256,created_by_user_id,created_at_utc '
            . 'FROM forwext_theme_revisions WHERE revision_id=:revision_id LIMIT 1',
            ['revision_id' => $revisionId->value()],
        ));
        if ($row === null) {
            return null;
        }

        $json = $row['payload_json'] ?? null;
        $checksum = $row['checksum_sha256'] ?? null;
        if (!is_string($json) || !is_string($checksum) || preg_match('/^[a-f0-9]{64}$/D', $checksum) !== 1) {
            throw new InvalidArgumentException('Stored theme revision is invalid.');
        }
        if (!hash_equals($checksum, hash('sha256', $json))) {
            throw new InvalidArgumentException('Stored theme revision checksum does not match.');
        }

        try {
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Stored theme revision payload is invalid.', previous: $exception);
        }
        if (!is_array($payload)) {
            throw new InvalidArgumentException('Stored theme revision payload is invalid.');
        }

        return new ThemeRevision(
            EntityId::fromString(self::requiredString($row, 'revision_id')),
            EntityId::fromString(self::requiredString($row, 'theme_id')),
            ThemePayload::fromArray($payload),
            EntityId::fromString(self::requiredString($row, 'created_by_user_id')),
            self::date(self::requiredString($row, 'created_at_utc')),
        );
    }

    public function revisions(EntityId $themeId, int $limit = 50): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Theme revision history limit is invalid.');
        }

        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT revision_id FROM forwext_theme_revisions WHERE theme_id=:theme_id '
            . 'ORDER BY created_at_utc DESC,revision_id DESC LIMIT ' . $limit,
            ['theme_id' => $themeId->value()],
        ));

        $revisions = [];
        foreach ($rows as $row) {
            $revision = $this->revision(EntityId::fromString(self::requiredString($row, 'revision_id')));
            if ($revision !== null) {
                $revisions[] = $revision;
            }
        }

        return $revisions;
    }

    public function saveStaging(
        ThemeDefinition $theme,
        ThemeRevision $revision,
        EntityId $actor,
        DateTimeImmutable $updatedAt,
    ): void {
        $json = json_encode(
            $revision->payload->toArray(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        $checksum = hash('sha256', $json);
        $created = self::formatDate($theme->createdAt);
        $updated = self::formatDate($updatedAt);

        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_themes '
            . '(theme_id,theme_key,name,parent_theme_id,staging_revision_id,published_revision_id,created_by_user_id,updated_by_user_id,created_at_utc,updated_at_utc) '
            . 'VALUES (:theme_id,:theme_key,:name,:parent_theme_id,NULL,NULL,:actor,:actor,:created_at,:updated_at) '
            . 'ON DUPLICATE KEY UPDATE name=VALUES(name),parent_theme_id=VALUES(parent_theme_id),'
            . 'updated_by_user_id=VALUES(updated_by_user_id),updated_at_utc=VALUES(updated_at_utc)',
            [
                'theme_id' => $theme->themeId->value(),
                'theme_key' => $theme->key,
                'name' => $theme->name,
                'parent_theme_id' => $theme->parentThemeId?->value(),
                'actor' => $actor->value(),
                'created_at' => $created,
                'updated_at' => $updated,
            ],
        ));

        $storedId = $this->database->fetchValue(new CompiledQuery(
            'SELECT theme_id FROM forwext_themes WHERE theme_key=:theme_key LIMIT 1',
            ['theme_key' => $theme->key],
        ));
        if (!is_string($storedId) || !hash_equals($theme->themeId->value(), $storedId)) {
            throw new InvalidArgumentException('Theme was created concurrently; reload before saving.');
        }

        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_theme_revisions '
            . '(revision_id,theme_id,payload_json,checksum_sha256,created_by_user_id,created_at_utc) '
            . 'VALUES (:revision_id,:theme_id,:payload_json,:checksum,:actor,:created_at)',
            [
                'revision_id' => $revision->revisionId->value(),
                'theme_id' => $theme->themeId->value(),
                'payload_json' => $json,
                'checksum' => $checksum,
                'actor' => $actor->value(),
                'created_at' => self::formatDate($revision->createdAt),
            ],
        ));

        $this->database->execute(new CompiledQuery(
            'UPDATE forwext_themes SET staging_revision_id=:revision_id,name=:name,parent_theme_id=:parent_theme_id,'
            . 'updated_by_user_id=:actor,updated_at_utc=:updated_at WHERE theme_id=:theme_id',
            [
                'revision_id' => $revision->revisionId->value(),
                'name' => $theme->name,
                'parent_theme_id' => $theme->parentThemeId?->value(),
                'actor' => $actor->value(),
                'updated_at' => $updated,
                'theme_id' => $theme->themeId->value(),
            ],
        ));
    }

    public function publish(
        EntityId $themeId,
        EntityId $expectedStagingRevisionId,
        EntityId $actor,
        DateTimeImmutable $updatedAt,
    ): bool {
        return $this->database->execute(new CompiledQuery(
            'UPDATE forwext_themes SET published_revision_id=staging_revision_id,updated_by_user_id=:actor,updated_at_utc=:updated_at '
            . 'WHERE theme_id=:theme_id AND staging_revision_id=:expected_revision',
            [
                'actor' => $actor->value(),
                'updated_at' => self::formatDate($updatedAt),
                'theme_id' => $themeId->value(),
                'expected_revision' => $expectedStagingRevisionId->value(),
            ],
        )) === 1;
    }

    public function pointStaging(
        EntityId $themeId,
        EntityId $revisionId,
        EntityId $actor,
        DateTimeImmutable $updatedAt,
    ): bool {
        return $this->database->execute(new CompiledQuery(
            'UPDATE forwext_themes SET staging_revision_id=:revision_id,updated_by_user_id=:actor,updated_at_utc=:updated_at '
            . 'WHERE theme_id=:theme_id AND EXISTS (SELECT 1 FROM forwext_theme_revisions r '
            . 'WHERE r.revision_id=:revision_id_check AND r.theme_id=:theme_id_check)',
            [
                'revision_id' => $revisionId->value(),
                'actor' => $actor->value(),
                'updated_at' => self::formatDate($updatedAt),
                'theme_id' => $themeId->value(),
                'revision_id_check' => $revisionId->value(),
                'theme_id_check' => $themeId->value(),
            ],
        )) === 1;
    }

    /** @param array<string,mixed> $row */
    private function definitionFromRow(array $row): ThemeDefinition
    {
        return new ThemeDefinition(
            EntityId::fromString(self::requiredString($row, 'theme_id')),
            self::requiredString($row, 'theme_key'),
            self::requiredString($row, 'name'),
            self::nullableId($row['parent_theme_id'] ?? null),
            self::nullableId($row['staging_revision_id'] ?? null),
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
            throw new InvalidArgumentException('Stored theme row is invalid.');
        }
        return $value;
    }

    private static function date(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (!$date instanceof DateTimeImmutable) {
            throw new InvalidArgumentException('Stored theme date is invalid.');
        }
        return $date;
    }

    private static function formatDate(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
