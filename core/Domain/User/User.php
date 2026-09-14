<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\User;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\Entity;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\Event\RecordsDomainEvents;
use InvalidArgumentException;

final class User implements Entity
{
    use RecordsDomainEvents;

    /** @var array<string, UserCustomFieldValue> */
    private array $customFields;
    /** @var list<UserHistoryEntry> */
    private array $pendingHistory = [];

    private function __construct(
        private readonly EntityId $userId,
        private Username $username,
        private EmailAddress $email,
        private UserStatus $status,
        private UserLocale $locale,
        private UserTimezone $timezone,
        array $customFields,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
        private int $version,
    ) {
        UserId::assert($userId);
        if ($version < 0) {
            throw new InvalidArgumentException('User aggregate version cannot be negative.');
        }
        $this->customFields = self::normalizeCustomFields($customFields);
    }

    public static function create(
        EntityId $id,
        Username $username,
        EmailAddress $email,
        UserStatus $initialStatus,
        UserLocale $locale,
        UserTimezone $timezone,
        DateTimeImmutable $now,
    ): self {
        if (!in_array($initialStatus, [
            UserStatus::PendingEmailVerification,
            UserStatus::PendingApproval,
            UserStatus::Active,
        ], true)) {
            throw new InvalidArgumentException('New users must begin in a registration-capable state.');
        }

        $now = self::utc($now);
        $user = new self($id, $username, $email, $initialStatus, $locale, $timezone, [], $now, $now, 0);
        $user->recordChange(
            'user.created',
            ['username', 'email', 'status', 'locale', 'timezone'],
            $now,
            reasonCode: 'account.created',
        );
        return $user;
    }

    /** @param array<string, UserCustomFieldValue> $customFields */
    public static function hydrate(
        EntityId $id,
        Username $username,
        EmailAddress $email,
        UserStatus $status,
        UserLocale $locale,
        UserTimezone $timezone,
        array $customFields,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
        int $version,
    ): self {
        if ($version < 1) {
            throw new InvalidArgumentException('Hydrated users require a persisted aggregate version.');
        }
        return new self(
            $id,
            $username,
            $email,
            $status,
            $locale,
            $timezone,
            $customFields,
            self::utc($createdAt),
            self::utc($updatedAt),
            $version,
        );
    }

    public function id(): EntityId
    {
        return $this->userId;
    }

    public function username(): Username
    {
        return $this->username;
    }

    public function email(): EmailAddress
    {
        return $this->email;
    }

    public function status(): UserStatus
    {
        return $this->status;
    }

    public function locale(): UserLocale
    {
        return $this->locale;
    }

    public function timezone(): UserTimezone
    {
        return $this->timezone;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function version(): int
    {
        return $this->version;
    }

    /** @return array<string, UserCustomFieldValue> */
    public function customFields(): array
    {
        return $this->customFields;
    }

    public function changeUsername(Username $username, DateTimeImmutable $at, ?EntityId $actorId = null): bool
    {
        if ($this->username->display() === $username->display() && $this->username->key() === $username->key()) {
            return false;
        }
        $this->username = $username;
        $this->touch($at);
        $this->recordChange(
            'user.username_changed',
            ['username'],
            $at,
            $actorId,
            reasonCode: 'account.username_changed',
        );
        return true;
    }

    public function changeEmail(EmailAddress $email, DateTimeImmutable $at, ?EntityId $actorId = null): bool
    {
        if ($this->email->value() === $email->value() && $this->email->key() === $email->key()) {
            return false;
        }
        $this->email = $email;
        $this->touch($at);
        $this->recordChange(
            'user.email_changed',
            ['email'],
            $at,
            $actorId,
            reasonCode: 'account.email_changed',
        );
        return true;
    }

    public function changeLocale(UserLocale $locale, DateTimeImmutable $at, ?EntityId $actorId = null): bool
    {
        if ($this->locale->value() === $locale->value()) {
            return false;
        }
        $this->locale = $locale;
        $this->touch($at);
        $this->recordChange(
            'user.locale_changed',
            ['locale'],
            $at,
            $actorId,
            reasonCode: 'account.locale_changed',
        );
        return true;
    }

    public function changeTimezone(UserTimezone $timezone, DateTimeImmutable $at, ?EntityId $actorId = null): bool
    {
        if ($this->timezone->value() === $timezone->value()) {
            return false;
        }
        $this->timezone = $timezone;
        $this->touch($at);
        $this->recordChange(
            'user.timezone_changed',
            ['timezone'],
            $at,
            $actorId,
            reasonCode: 'account.timezone_changed',
        );
        return true;
    }

    public function changeStatus(
        UserStatus $target,
        DateTimeImmutable $at,
        UserStatusTransitionPolicy $policy = new UserStatusTransitionPolicy(),
        ?EntityId $actorId = null,
        ?string $reasonCode = null,
    ): bool {
        if ($this->status === $target) {
            return false;
        }
        $policy->assertAllowed($this->status, $target);
        $from = $this->status;
        $this->status = $target;
        $this->touch($at);
        $this->recordChange(
            'user.status_changed',
            ['status'],
            $at,
            $actorId,
            $from,
            $target,
            $reasonCode ?? 'account.status_changed',
        );
        return true;
    }

    public function setCustomField(
        UserCustomFieldKey $key,
        UserCustomFieldValue $value,
        DateTimeImmutable $at,
        ?EntityId $actorId = null,
    ): bool {
        $name = $key->value();
        $current = $this->customFields[$name] ?? null;
        if ($current !== null && $current->type === $value->type && hash_equals($current->encoded(), $value->encoded())) {
            return false;
        }
        $this->customFields[$name] = $value;
        ksort($this->customFields);
        $this->touch($at);
        $this->recordChange(
            'user.custom_field_changed',
            ['custom_field.' . $name],
            $at,
            $actorId,
            reasonCode: 'account.custom_field_changed',
        );
        return true;
    }

    public function removeCustomField(UserCustomFieldKey $key, DateTimeImmutable $at, ?EntityId $actorId = null): bool
    {
        $name = $key->value();
        if (!isset($this->customFields[$name])) {
            return false;
        }
        unset($this->customFields[$name]);
        $this->touch($at);
        $this->recordChange(
            'user.custom_field_removed',
            ['custom_field.' . $name],
            $at,
            $actorId,
            reasonCode: 'account.custom_field_removed',
        );
        return true;
    }

    /** @return list<UserHistoryEntry> */
    public function pendingHistory(): array
    {
        return $this->pendingHistory;
    }

    public function markPersisted(int $newVersion): void
    {
        if ($newVersion !== $this->version + 1) {
            throw new InvalidArgumentException('Persisted user version must advance exactly by one.');
        }
        $this->version = $newVersion;
        $this->pendingHistory = [];
    }

    private function touch(DateTimeImmutable $at): void
    {
        $at = self::utc($at);
        if ($at < $this->createdAt || $at < $this->updatedAt) {
            throw new InvalidArgumentException('User mutation timestamp cannot move backwards.');
        }
        $this->updatedAt = $at;
    }

    /** @param list<string> $fields */
    private function recordChange(
        string $eventType,
        array $fields,
        DateTimeImmutable $at,
        ?EntityId $actorId = null,
        ?UserStatus $fromStatus = null,
        ?UserStatus $toStatus = null,
        ?string $reasonCode = null,
    ): void {
        $at = self::utc($at);
        $this->pendingHistory[] = new UserHistoryEntry(
            $eventType,
            $fields,
            $at,
            $actorId,
            $fromStatus,
            $toStatus,
            $reasonCode,
        );
        $this->recordDomainEvent(new UserAccountEvent($this->userId, $eventType, $at));
    }

    /**
     * @param array<string, UserCustomFieldValue> $customFields
     * @return array<string, UserCustomFieldValue>
     */
    private static function normalizeCustomFields(array $customFields): array
    {
        $normalized = [];
        foreach ($customFields as $key => $value) {
            if (!$value instanceof UserCustomFieldValue) {
                throw new InvalidArgumentException('User custom fields must contain typed values.');
            }
            $canonicalKey = UserCustomFieldKey::fromString((string) $key)->value();
            $normalized[$canonicalKey] = $value;
        }
        ksort($normalized);
        return $normalized;
    }

    private static function utc(DateTimeImmutable $time): DateTimeImmutable
    {
        return $time->setTimezone(new DateTimeZone('UTC'));
    }
}
