<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Session;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Auth\AuthException;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;

final readonly class DatabaseAuthSessionIndex implements AuthSessionIndex
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function find(string $sessionHash): ?AuthSessionIndexRecord
    {
        self::assertHash($sessionHash);
        $row = $this->database->fetchOne(new CompiledQuery(
            $this->selectSql() . ' WHERE `session_hash` = :session_hash LIMIT 1',
            ['session_hash' => $sessionHash],
        ));

        return $row === null ? null : $this->hydrate($row);
    }

    public function register(AuthSessionIndexRecord $record): void
    {
        $this->database->execute(new CompiledQuery(
            'INSERT INTO `forwext_auth_session_index` '
            . '(`session_hash`, `user_id`, `device_id`, `credential_version`, `issued_at_utc`, '
            . '`expires_at_utc`, `last_seen_at_utc`, `revoked_at_utc`) '
            . 'VALUES (:session_hash, :user_id, :device_id, :credential_version, :issued_at, :expires_at, :last_seen_at, NULL)',
            [
                'session_hash' => $record->sessionHash,
                'user_id' => $record->userId->value(),
                'device_id' => $record->deviceId,
                'credential_version' => $record->credentialVersion,
                'issued_at' => self::format($record->issuedAt),
                'expires_at' => self::format($record->expiresAt),
                'last_seen_at' => self::format($record->lastSeenAt),
            ],
        ));
    }

    public function touch(string $sessionHash, DateTimeImmutable $at): void
    {
        self::assertHash($sessionHash);
        $this->database->execute(new CompiledQuery(
            'UPDATE `forwext_auth_session_index` SET `last_seen_at_utc` = GREATEST(`last_seen_at_utc`, :last_seen_at) '
            . 'WHERE `session_hash` = :session_hash AND `revoked_at_utc` IS NULL',
            ['last_seen_at' => self::format($at), 'session_hash' => $sessionHash],
        ));
    }

    public function revoke(string $sessionHash, DateTimeImmutable $at): void
    {
        self::assertHash($sessionHash);
        $this->database->execute(new CompiledQuery(
            'UPDATE `forwext_auth_session_index` SET `revoked_at_utc` = COALESCE(`revoked_at_utc`, :revoked_at) '
            . 'WHERE `session_hash` = :session_hash',
            ['revoked_at' => self::format($at), 'session_hash' => $sessionHash],
        ));
    }

    public function revokeForUser(EntityId $userId, string $sessionHash, DateTimeImmutable $at): bool
    {
        UserId::assert($userId);
        self::assertHash($sessionHash);

        return $this->database->execute(new CompiledQuery(
            'UPDATE `forwext_auth_session_index` SET `revoked_at_utc` = :revoked_at '
            . 'WHERE `session_hash` = :session_hash AND `user_id` = :user_id AND `revoked_at_utc` IS NULL',
            [
                'revoked_at' => self::format($at),
                'session_hash' => $sessionHash,
                'user_id' => $userId->value(),
            ],
        )) === 1;
    }

    public function revokeOthers(EntityId $userId, string $exceptSessionHash, DateTimeImmutable $at): int
    {
        UserId::assert($userId);
        self::assertHash($exceptSessionHash);

        return $this->database->execute(new CompiledQuery(
            'UPDATE `forwext_auth_session_index` SET `revoked_at_utc` = :revoked_at '
            . 'WHERE `user_id` = :user_id AND `session_hash` <> :except_hash '
            . 'AND `revoked_at_utc` IS NULL AND `expires_at_utc` > :now',
            [
                'revoked_at' => self::format($at),
                'user_id' => $userId->value(),
                'except_hash' => $exceptSessionHash,
                'now' => self::format($at),
            ],
        ));
    }

    public function activeForUser(EntityId $userId, DateTimeImmutable $now, int $limit = 50): array
    {
        UserId::assert($userId);
        if ($limit < 1 || $limit > 200) {
            throw new AuthException('Authentication session list limit is invalid.');
        }

        $rows = $this->database->fetchAll(new CompiledQuery(
            $this->selectSql()
            . ' WHERE `user_id` = :user_id AND `revoked_at_utc` IS NULL AND `expires_at_utc` > :now '
            . 'ORDER BY `last_seen_at_utc` DESC, `issued_at_utc` DESC LIMIT ' . $limit,
            ['user_id' => $userId->value(), 'now' => self::format($now)],
        ));

        return array_map($this->hydrate(...), $rows);
    }

    private function selectSql(): string
    {
        return 'SELECT `session_hash`, `user_id`, `device_id`, `credential_version`, `issued_at_utc`, '
            . '`expires_at_utc`, `last_seen_at_utc`, `revoked_at_utc` FROM `forwext_auth_session_index`';
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): AuthSessionIndexRecord
    {
        return new AuthSessionIndexRecord(
            (string) $row['session_hash'],
            UserId::fromStored((string) $row['user_id']),
            (string) $row['device_id'],
            (int) $row['credential_version'],
            self::parse((string) $row['issued_at_utc']),
            self::parse((string) $row['expires_at_utc']),
            self::parse((string) $row['last_seen_at_utc']),
            ($row['revoked_at_utc'] ?? null) === null ? null : self::parse((string) $row['revoked_at_utc']),
        );
    }

    private static function assertHash(string $value): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new AuthException('Authentication session hash is invalid.');
        }
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function parse(string $value): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (!$parsed instanceof DateTimeImmutable) {
            throw new AuthException('Stored authentication session timestamp is invalid.');
        }
        return $parsed;
    }
}
