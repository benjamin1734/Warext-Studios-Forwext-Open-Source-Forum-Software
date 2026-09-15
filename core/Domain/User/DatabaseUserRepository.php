<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\User;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
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
        return $this->findBy('`user_id` = :value', $id->value());
    }

    public function findByUsername(Username $username): ?User
    {
        return $this->findBy('`username_key` = :value', $username->key());
    }

    public function findByEmail(EmailAddress $email): ?User
    {
        return $this->findBy('`email_key` = :value', $email->key());
    }

    public function existsByUsername(Username $username, ?EntityId $except = null): bool
    {
        return $this->exists('username_key', $username->key(), $except);
    }

    public function existsByEmail(EmailAddress $email, ?EntityId $except = null): bool
    {
        return $this->exists('email_key', $email->key(), $except);
    }

    public function save(User $user): void
    {
        $newVersion = $this->database->transaction(
            function (TransactionalQueryExecutor $database) use ($user): int {
                $expectedVersion = $user->version();
                $newVersion = $expectedVersion + 1;
                $parameters = [
                    'user_id' => $user->id()->value(),
                    'username' => $user->username()->display(),
                    'username_key' => $user->username()->key(),
                    'email' => $user->email()->value(),
                    'email_key' => $user->email()->key(),
                    'status' => $user->status()->value,
                    'locale' => $user->locale()->value(),
                    'timezone' => $user->timezone()->value(),
                    'version' => $newVersion,
                    'created_at' => self::format($user->createdAt()),
                    'updated_at' => self::format($user->updatedAt()),
                ];

                if ($expectedVersion === 0) {
                    $affected = $database->execute(new CompiledQuery(
                        'INSERT INTO `forwext_users` '
                        . '(`user_id`, `username`, `username_key`, `email`, `email_key`, `status`, '
                        . '`locale`, `timezone`, `version`, `created_at_utc`, `updated_at_utc`) '
                        . 'VALUES (:user_id, :username, :username_key, :email, :email_key, :status, '
                        . ':locale, :timezone, :version, :created_at, :updated_at)',
                        $parameters,
                    ));
                } else {
                    $parameters['expected_version'] = $expectedVersion;
                    $affected = $database->execute(new CompiledQuery(
                        'UPDATE `forwext_users` SET '
                        . '`username` = :username, `username_key` = :username_key, '
                        . '`email` = :email, `email_key` = :email_key, `status` = :status, '
                        . '`locale` = :locale, `timezone` = :timezone, `version` = :version, '
                        . '`updated_at_utc` = :updated_at '
                        . 'WHERE `user_id` = :user_id AND `version` = :expected_version',
                        $parameters,
                    ));
                }

                if ($affected !== 1) {
                    throw new UserConcurrencyException(
                        'User persistence failed because the stored version changed.',
                    );
                }

                $this->replaceCustomFields($database, $user);
                $this->appendHistory($database, $user);
                return $newVersion;
            },
        );

        $user->markPersisted($newVersion);
    }

    public function history(EntityId $id, int $limit = 100, int $offset = 0): array
    {
        UserId::assert($id);
        if ($limit < 1 || $limit > 500 || $offset < 0 || $offset > 1_000_000) {
            throw new \InvalidArgumentException('User history pagination is invalid.');
        }

        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT `event_type`, `changed_fields_json`, `occurred_at_utc`, `actor_user_id`, '
            . '`from_status`, `to_status`, `reason_code` '
            . 'FROM `forwext_user_history` WHERE `user_id` = :user_id '
            . 'ORDER BY `history_id` DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
            ['user_id' => $id->value()],
        ));

        $history = [];
        foreach ($rows as $row) {
            $history[] = $this->hydrateHistory($row);
        }
        return $history;
    }

    private function findBy(string $where, string $value): ?User
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT `user_id`, `username`, `email`, `status`, `locale`, `timezone`, '
            . '`version`, `created_at_utc`, `updated_at_utc` '
            . 'FROM `forwext_users` WHERE ' . $where . ' LIMIT 1',
            ['value' => $value],
        ));
        if ($row === null) {
            return null;
        }

        $customRows = $this->database->fetchAll(new CompiledQuery(
            'SELECT `field_key`, `value_type`, `value_json` '
            . 'FROM `forwext_user_custom_field_values` '
            . 'WHERE `user_id` = :user_id ORDER BY `field_key`',
            ['user_id' => (string) $row['user_id']],
        ));
        $customFields = [];
        foreach ($customRows as $customRow) {
            $customFields[(string) $customRow['field_key']] = UserCustomFieldValue::fromStored(
                UserCustomFieldType::from((string) $customRow['value_type']),
                (string) $customRow['value_json'],
            );
        }

        return User::hydrate(
            UserId::fromStored((string) $row['user_id']),
            Username::fromString((string) $row['username']),
            EmailAddress::fromString((string) $row['email']),
            UserStatus::from((string) $row['status']),
            UserLocale::fromString((string) $row['locale']),
            UserTimezone::fromString((string) $row['timezone']),
            $customFields,
            self::parse((string) $row['created_at_utc']),
            self::parse((string) $row['updated_at_utc']),
            (int) $row['version'],
        );
    }

    private function exists(string $column, string $value, ?EntityId $except): bool
    {
        if (!in_array($column, ['username_key', 'email_key'], true)) {
            throw new \LogicException('Unsupported identity column.');
        }

        $sql = 'SELECT COUNT(*) FROM `forwext_users` WHERE `' . $column . '` = :value';
        $parameters = ['value' => $value];
        if ($except !== null) {
            UserId::assert($except);
            $sql .= ' AND `user_id` <> :except';
            $parameters['except'] = $except->value();
        }

        return (int) $this->database->fetchValue(new CompiledQuery($sql, $parameters)) > 0;
    }

    private function replaceCustomFields(TransactionalQueryExecutor $database, User $user): void
    {
        $database->execute(new CompiledQuery(
            'DELETE FROM `forwext_user_custom_field_values` WHERE `user_id` = :user_id',
            ['user_id' => $user->id()->value()],
        ));

        foreach ($user->customFields() as $key => $value) {
            $database->execute(new CompiledQuery(
                'INSERT INTO `forwext_user_custom_field_values` '
                . '(`user_id`, `field_key`, `value_type`, `value_json`) '
                . 'VALUES (:user_id, :field_key, :value_type, :value_json)',
                [
                    'user_id' => $user->id()->value(),
                    'field_key' => $key,
                    'value_type' => $value->type->value,
                    'value_json' => $value->encoded(),
                ],
            ));
        }
    }

    private function appendHistory(TransactionalQueryExecutor $database, User $user): void
    {
        foreach ($user->pendingHistory() as $entry) {
            $database->execute(new CompiledQuery(
                'INSERT INTO `forwext_user_history` '
                . '(`user_id`, `event_type`, `changed_fields_json`, `occurred_at_utc`, '
                . '`actor_user_id`, `from_status`, `to_status`, `reason_code`) '
                . 'VALUES (:user_id, :event_type, :fields, :occurred_at, :actor, '
                . ':from_status, :to_status, :reason)',
                [
                    'user_id' => $user->id()->value(),
                    'event_type' => $entry->eventType,
                    'fields' => json_encode($entry->changedFields, JSON_THROW_ON_ERROR),
                    'occurred_at' => self::format($entry->occurredAt),
                    'actor' => $entry->actorId?->value(),
                    'from_status' => $entry->fromStatus?->value,
                    'to_status' => $entry->toStatus?->value,
                    'reason' => $entry->reasonCode,
                ],
            ));
        }
    }

    /** @param array<string, mixed> $row */
    private function hydrateHistory(array $row): UserHistoryEntry
    {
        try {
            $fields = json_decode(
                (string) $row['changed_fields_json'],
                true,
                32,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('Stored user history JSON is invalid.', 0, $exception);
        }

        if (!is_array($fields)) {
            throw new RuntimeException('Stored user history fields are invalid.');
        }
        foreach ($fields as $field) {
            if (!is_string($field)) {
                throw new RuntimeException('Stored user history field is invalid.');
            }
        }

        return new UserHistoryEntry(
            (string) $row['event_type'],
            array_values($fields),
            self::parse((string) $row['occurred_at_utc']),
            isset($row['actor_user_id']) && is_string($row['actor_user_id'])
                ? UserId::fromStored($row['actor_user_id'])
                : null,
            isset($row['from_status']) && is_string($row['from_status'])
                ? UserStatus::from($row['from_status'])
                : null,
            isset($row['to_status']) && is_string($row['to_status'])
                ? UserStatus::from($row['to_status'])
                : null,
            isset($row['reason_code']) && is_string($row['reason_code'])
                ? $row['reason_code']
                : null,
        );
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function parse(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s.u',
            $value,
            new DateTimeZone('UTC'),
        );
        if (!$date instanceof DateTimeImmutable) {
            throw new RuntimeException('Stored user timestamp is invalid.');
        }
        return $date;
    }
}
