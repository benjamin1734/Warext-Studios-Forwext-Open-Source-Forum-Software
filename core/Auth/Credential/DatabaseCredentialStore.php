<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Credential;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Auth\AuthException;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;

final readonly class DatabaseCredentialStore implements CredentialStore
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function find(EntityId $userId): ?CredentialRecord
    {
        UserId::assert($userId);
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT `password_hash`, `credential_version`, `password_changed_at_utc` '
            . 'FROM `forwext_user_credentials` WHERE `user_id` = :user_id LIMIT 1',
            ['user_id' => $userId->value()],
        ));
        if ($row === null) {
            return null;
        }
        $hash = $row['password_hash'] ?? null;
        $version = (int) ($row['credential_version'] ?? 0);
        $changed = $row['password_changed_at_utc'] ?? null;
        if (!is_string($hash) || !is_string($changed) || $version < 1) {
            throw new AuthException('Credential row is malformed.');
        }
        return new CredentialRecord($userId, $hash, $version, self::parseDate($changed));
    }

    public function create(EntityId $userId, string $passwordHash, DateTimeImmutable $changedAt): CredentialRecord
    {
        UserId::assert($userId);
        self::assertHash($passwordHash);
        $changedAt = self::utc($changedAt);
        $affected = $this->database->execute(new CompiledQuery(
            'INSERT INTO `forwext_user_credentials` '
            . '(`user_id`, `password_hash`, `credential_version`, `password_changed_at_utc`) '
            . 'VALUES (:user_id, :password_hash, 1, :password_changed_at_utc)',
            [
                'user_id' => $userId->value(),
                'password_hash' => $passwordHash,
                'password_changed_at_utc' => self::formatDate($changedAt),
            ],
        ));
        if ($affected !== 1) {
            throw new AuthException('Credential creation did not affect exactly one row.');
        }
        return new CredentialRecord($userId, $passwordHash, 1, $changedAt);
    }

    public function rehash(EntityId $userId, int $expectedVersion, string $passwordHash): CredentialRecord
    {
        UserId::assert($userId);
        self::assertHash($passwordHash);
        if ($expectedVersion < 1) {
            throw new AuthException('Credential expected version is invalid.');
        }
        $current = $this->find($userId);
        if ($current === null || $current->version !== $expectedVersion) {
            throw new AuthException('Credential changed concurrently or no longer exists.');
        }
        $affected = $this->database->execute(new CompiledQuery(
            'UPDATE `forwext_user_credentials` SET `password_hash` = :password_hash '
            . 'WHERE `user_id` = :user_id AND `credential_version` = :expected_version',
            [
                'password_hash' => $passwordHash,
                'user_id' => $userId->value(),
                'expected_version' => $expectedVersion,
            ],
        ));
        if ($affected !== 1) {
            throw new AuthException('Credential rehash changed concurrently.');
        }
        return new CredentialRecord($userId, $passwordHash, $expectedVersion, $current->passwordChangedAt);
    }

    public function replacePassword(
        EntityId $userId,
        int $expectedVersion,
        string $passwordHash,
        DateTimeImmutable $changedAt,
    ): CredentialRecord {
        UserId::assert($userId);
        self::assertHash($passwordHash);
        if ($expectedVersion < 1) {
            throw new AuthException('Credential expected version is invalid.');
        }
        $changedAt = self::utc($changedAt);
        $newVersion = $expectedVersion + 1;
        $affected = $this->database->execute(new CompiledQuery(
            'UPDATE `forwext_user_credentials` SET `password_hash` = :password_hash, '
            . '`credential_version` = :credential_version, `password_changed_at_utc` = :password_changed_at_utc '
            . 'WHERE `user_id` = :user_id AND `credential_version` = :expected_version',
            [
                'password_hash' => $passwordHash,
                'credential_version' => $newVersion,
                'password_changed_at_utc' => self::formatDate($changedAt),
                'user_id' => $userId->value(),
                'expected_version' => $expectedVersion,
            ],
        ));
        if ($affected !== 1) {
            throw new AuthException('Credential changed concurrently or no longer exists.');
        }
        return new CredentialRecord($userId, $passwordHash, $newVersion, $changedAt);
    }

    private static function assertHash(string $hash): void
    {
        if ($hash === '' || strlen($hash) > 255) {
            throw new AuthException('Password hash is invalid.');
        }
    }

    private static function utc(DateTimeImmutable $date): DateTimeImmutable
    {
        return $date->setTimezone(new DateTimeZone('UTC'));
    }

    private static function formatDate(DateTimeImmutable $date): string
    {
        return self::utc($date)->format('Y-m-d H:i:s.u');
    }

    private static function parseDate(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (!$date instanceof DateTimeImmutable) {
            throw new AuthException('Credential timestamp is invalid.');
        }
        return $date;
    }
}
