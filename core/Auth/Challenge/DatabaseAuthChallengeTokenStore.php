<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Challenge;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Auth\AuthException;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;

final readonly class DatabaseAuthChallengeTokenStore implements AuthChallengeTokenStore
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function issue(
        EntityId $userId,
        AuthChallengePurpose $purpose,
        DateTimeImmutable $now,
        int $ttlSeconds,
    ): string {
        UserId::assert($userId);
        if ($ttlSeconds < 60 || $ttlSeconds > 86400) {
            throw new AuthException('Authentication challenge TTL is outside safe bounds.');
        }
        $token = self::base64Url(random_bytes(32));
        $now = self::utc($now);
        $expires = $now->add(new DateInterval('PT' . $ttlSeconds . 'S'));
        $affected = $this->database->execute(new CompiledQuery(
            'INSERT INTO `forwext_auth_challenge_tokens` '
            . '(`token_hash`, `user_id`, `purpose`, `expires_at_utc`, `created_at_utc`, `consumed_at_utc`) '
            . 'VALUES (:token_hash, :user_id, :purpose, :expires_at_utc, :created_at_utc, NULL)',
            [
                'token_hash' => hash('sha256', $token),
                'user_id' => $userId->value(),
                'purpose' => $purpose->value,
                'expires_at_utc' => self::formatDate($expires),
                'created_at_utc' => self::formatDate($now),
            ],
        ));
        if ($affected !== 1) {
            throw new AuthException('Authentication challenge token was not persisted.');
        }
        return $token;
    }

    public function consume(
        string $token,
        AuthChallengePurpose $purpose,
        DateTimeImmutable $now,
    ): ?AuthChallengeGrant {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) !== 1) {
            return null;
        }
        $hash = hash('sha256', $token);
        $now = self::utc($now);

        return $this->database->transaction(function () use ($hash, $purpose, $now): ?AuthChallengeGrant {
            $row = $this->database->fetchOne(new CompiledQuery(
                'SELECT `user_id`, `purpose`, `expires_at_utc`, `consumed_at_utc` '
                . 'FROM `forwext_auth_challenge_tokens` WHERE `token_hash` = :token_hash LIMIT 1 FOR UPDATE',
                ['token_hash' => $hash],
                requiresTransaction: true,
            ));
            if ($row === null
                || ($row['consumed_at_utc'] ?? null) !== null
                || ($row['purpose'] ?? null) !== $purpose->value
                || !is_string($row['expires_at_utc'] ?? null)
                || self::parseDate($row['expires_at_utc']) <= $now
                || !is_string($row['user_id'] ?? null)
            ) {
                return null;
            }
            $affected = $this->database->execute(new CompiledQuery(
                'UPDATE `forwext_auth_challenge_tokens` SET `consumed_at_utc` = :consumed_at_utc '
                . 'WHERE `token_hash` = :token_hash AND `consumed_at_utc` IS NULL',
                ['consumed_at_utc' => self::formatDate($now), 'token_hash' => $hash],
            ));
            if ($affected !== 1) {
                return null;
            }
            return new AuthChallengeGrant(UserId::fromStored($row['user_id']), $purpose);
        });
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
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
            throw new AuthException('Authentication challenge timestamp is invalid.');
        }
        return $date;
    }
}
