<?php

declare(strict_types=1);

namespace Forwext\Core\Infrastructure\User;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\EmailAddress;
use Forwext\Core\Domain\User\User;
use Forwext\Core\Domain\User\UserConcurrencyException;
use Forwext\Core\Domain\User\UserCustomFieldKey;
use Forwext\Core\Domain\User\UserCustomFieldType;
use Forwext\Core\Domain\User\UserCustomFieldValue;
use Forwext\Core\Domain\User\UserHistoryEntry;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Domain\User\UserLocale;
use Forwext\Core\Domain\User\Username;
use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Domain\User\UserStatus;
use Forwext\Core\Domain\User\UserTimezone;
use JsonException;
use RuntimeException;

final readonly class DatabaseUserRepository implements UserRepository
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function find(EntityId $id): ?User
    {
        UserId::assert($id);
        return $this->findBy('`user_id` = :lookup', $id->value());
    }

    public function findByUsername(Username $username): ?User
    {
        return $this->findBy('`username_key` = :lookup', $username->key());
    }

    public function findByEmail(EmailAddress $email): ?User
    {
        return $this->findBy('`email_key` = :lookup', $email->key());
    }

    public function save(User $user): void
    {
        UserId::assert($user->id());
        $history = $user->pendingHistory();
        if ($user->version() > 0 && $history === []) {
            return;
        }

        $expectedVersion = $user->version();
        $newVersion = $expectedVersion + 1;

        $this->database->transaction(function () use ($user, $expectedVersion, $newVersion, $history): void {
            if ($expectedVersion === 0) {
                $this->insertUser($user, $newVersion);
            } else {
                $this->updateUser($user, $expectedVersion, $newVersion);
            }
            $this->replaceCustomFields($user);
            $this->appendHistory($user->id(), $history);
        });

        $user->markPersisted($newVersion);
    }

    public function history(EntityId $id, int $limit = 100, int $offset = 0): array
    {
        UserId::assert($id);
        if ($limit < 1 || $limit > 500 || $offset < 0 || $offset > 100000) {
            throw new \InvalidArgumentException('User history pagination is outside the allowed range.');
        }
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT `event_type`, `changed_fields_json`, `occurred_at_utc`, `actor_user_id`, '
            . '`from_status`, `to_status`, `reason_code` '
            . 'FROM `forwext_user_history` WHERE `user_id` = :user_id '
            . 'ORDER BY `history_id` DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
            ['user_id' => $id->value()],
        ));

        $entries = [];
        foreach ($rows as $row) {
            $entries[] = $this->hydrateHistory($row);
        }
        return $entries;
    }

    private function findBy(string $predicate, string $lookup): ?User
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT `user_id`, `username`, `username_key`, `email`, `email_key`, `status`, '
            . '`locale`, `timezone`, `version`, `created_at_utc`, `updated_at_utc` '
            . 'FROM `forwext_users` WHERE ' . $predicate . ' LIMIT 1',
            ['lookup' => $lookup],
        ));
        if ($row === null) {
            return null;
        }

        $id = UserId::fromStored(self::stringField($row, 'user_id'));
        return User::hydrate(
            $id,
            Username::fromStored(self::stringField($row, 'username'), self::stringField($row, 'username_key')),
            EmailAddress::fromStored(self::stringField($row, 'email'), self::stringField($row, 'email_key')),
            UserStatus::from(self::stringField($row, 'status')),
            UserLocale::fromString(self::stringField($row, 'locale')),
            UserTimezone::fromString(self::stringField($row, 'timezone')),
            $this->loadCustomFields($id),
            self::parseDate(self::stringField($row, 'created_at_utc')),
            self::parseDate(self::stringField($row, 'updated_at_utc')),
            self::positiveInteger($row, 'version'),
        );
    }

    private function insertUser(User $user, int $newVersion): void
    {
        $affected = $this->database->execute(new CompiledQuery(
            'INSERT INTO `forwext_users` '
            . '(`user_id`, `username`, `username_key`, `email`, `email_key`, `status`, `locale`, `timezone`, '
            . '`version`, `created_at_utc`, `updated_at_utc`) '
            . 'VALUES (:user_id, :username, :username_key, :email, :email_key, :status, :locale, :timezone, '
            . ':version, :created_at_utc, :updated_at_utc)',
            $this->insertParameters($user, $newVersion),
        ));
        if ($affected !== 1) {
            throw new RuntimeException('User insertion did not affect exactly one row.');
        }
    }

    private function updateUser(User $user, int $expectedVersion, int $newVersion): void
    {
        $parameters = $this->commonParameters($user, $newVersion);
        $parameters['expected_version'] = $expectedVersion;
        $affected = $this->database->execute(new CompiledQuery(
            'UPDATE `forwext_users` SET '
            . '`username` = :username, `username_key` = :username_key, `email` = :email, `email_key` = :email_key, '
            . '`status` = :status, `locale` = :locale, `timezone` = :timezone, `version` = :version, '
            . '`updated_at_utc` = :updated_at_utc '
            . 'WHERE `user_id` = :user_id AND `version` = :expected_version',
            $parameters,
        ));
        if ($affected !== 1) {
            throw new UserConcurrencyException('User aggregate changed since it was loaded.');
        }
    }

    /** @return array<string, string|int|null> */
    private function insertParameters(User $user, int $version): array
    {
        return [
            ...$this->commonParameters($user, $version),
            'created_at_utc' => self::formatDate($user->createdAt()),
        ];
    }

    /** @return array<string, string|int|null> */
    private function commonParameters(User $user, int $version): array
    {
        return [
            'user_id' => $user->id()->value(),
            'username' => $user->username()->display(),
            'username_key' => $user->username()->key(),
            'email' => $user->email()->value(),
            'email_key' => $user->email()->key(),
            'status' => $user->status()->value,
            'locale' => $user->locale()->value(),
            'timezone' => $user->timezone()->value(),
            'version' => $version,
            'updated_at_utc' => self::formatDate($user->updatedAt()),
        ];
    }

    private function replaceCustomFields(User $user): void
    {
        $this->database->execute(new CompiledQuery(
            'DELETE FROM `forwext_user_custom_field_values` WHERE `user_id` = :user_id',
            ['user_id' => $user->id()->value()],
        ));
        foreach ($user->customFields() as $key => $value) {
            $affected = $this->database->execute(new CompiledQuery(
                'INSERT INTO `forwext_user_custom_field_values` (`user_id`, `field_key`, `value_type`, `value_json`) '
                . 'VALUES (:user_id, :field_key, :value_type, :value_json)',
                [
                    'user_id' => $user->id()->value(),
                    'field_key' => $key,
                    'value_type' => $value->type->value,
                    'value_json' => $value->encoded(),
                ],
            ));
            if ($affected !== 1) {
                throw new RuntimeException('User custom field insertion did not affect exactly one row.');
            }
        }
    }

    /** @return array<string, UserCustomFieldValue> */
    private function loadCustomFields(EntityId $id): array
    {
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT `field_key`, `value_type`, `value_json` '
            . 'FROM `forwext_user_custom_field_values` WHERE `user_id` = :user_id ORDER BY `field_key` ASC',
            ['user_id' => $id->value()],
        ));
        $values = [];
        foreach ($rows as $row) {
            $key = UserCustomFieldKey::fromString(self::stringField($row, 'field_key'))->value();
            $type = UserCustomFieldType::from(self::stringField($row, 'value_type'));
            $values[$key] = UserCustomFieldValue::fromStored($type, self::stringField($row, 'value_json'));
        }
        return $values;
    }

    /** @param list<UserHistoryEntry> $history */
    private function appendHistory(EntityId $userId, array $history): void
    {
        foreach ($history as $entry) {
            try {
                $changedFields = json_encode($entry->changedFields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            } catch (JsonException $exception) {
                throw new RuntimeException('Unable to encode user history changed fields.', previous: $exception);
            }
            $affected = $this->database->execute(new CompiledQuery(
                'INSERT INTO `forwext_user_history` '
                . '(`user_id`, `event_type`, `changed_fields_json`, `occurred_at_utc`, `actor_user_id`, '
                . '`from_status`, `to_status`, `reason_code`) '
                . 'VALUES (:user_id, :event_type, :changed_fields_json, :occurred_at_utc, :actor_user_id, '
                . ':from_status, :to_status, :reason_code)',
                [
                    'user_id' => $userId->value(),
                    'event_type' => $entry->eventType,
                    'changed_fields_json' => $changedFields,
                    'occurred_at_utc' => self::formatDate($entry->occurredAt),
                    'actor_user_id' => $entry->actorId?->value(),
                    'from_status' => $entry->fromStatus?->value,
                    'to_status' => $entry->toStatus?->value,
                    'reason_code' => $entry->reasonCode,
                ],
            ));
            if ($affected !== 1) {
                throw new RuntimeException('User history insertion did not affect exactly one row.');
            }
        }
    }

    /** @param array<string, mixed> $row */
    private function hydrateHistory(array $row): UserHistoryEntry
    {
        try {
            $fields = json_decode(self::stringField($row, 'changed_fields_json'), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Stored user history changed fields are invalid JSON.', previous: $exception);
        }
        if (!is_array($fields) || array_filter($fields, static fn (mixed $field): bool => !is_string($field)) !== []) {
            throw new RuntimeException('Stored user history changed fields have an invalid shape.');
        }
        /** @var list<string> $fields */

        $actor = self::nullableStringField($row, 'actor_user_id');
        $from = self::nullableStringField($row, 'from_status');
        $to = self::nullableStringField($row, 'to_status');
        $reason = self::nullableStringField($row, 'reason_code');

        return new UserHistoryEntry(
            self::stringField($row, 'event_type'),
            array_values($fields),
            self::parseDate(self::stringField($row, 'occurred_at_utc')),
            $actor === null ? null : UserId::fromStored($actor),
            $from === null ? null : UserStatus::from($from),
            $to === null ? null : UserStatus::from($to),
            $reason,
        );
    }

    /** @param array<string, mixed> $row */
    private static function stringField(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Stored user field "%s" is invalid.', $key));
        }
        return $value;
    }

    /** @param array<string, mixed> $row */
    private static function nullableStringField(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Stored nullable user field "%s" is invalid.', $key));
        }
        return $value;
    }

    /** @param array<string, mixed> $row */
    private static function positiveInteger(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (is_int($value)) {
            $number = $value;
        } elseif (is_string($value) && ctype_digit($value)) {
            $number = (int) $value;
        } else {
            throw new RuntimeException(sprintf('Stored user field "%s" is not an integer.', $key));
        }
        if ($number < 1) {
            throw new RuntimeException(sprintf('Stored user field "%s" must be positive.', $key));
        }
        return $number;
    }

    private static function formatDate(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function parseDate(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d H:i:s.u') !== $value) {
            throw new RuntimeException('Stored user timestamp is invalid.');
        }
        return $date;
    }
}
