<?php

declare(strict_types=1);

namespace Forwext\Core\Registration;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Domain\User\UserStatus;

final readonly class DatabaseEmailVerificationTokenStore implements EmailVerificationTokenStore
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function issue(
        EntityId $userId,
        UserStatus $targetStatus,
        DateTimeImmutable $now,
        int $ttlSeconds,
    ): string {
        UserId::assert($userId);
        if (!in_array($targetStatus, [UserStatus::PendingApproval, UserStatus::Active], true)) {
            throw new RegistrationException('Email verification target status is invalid.');
        }
        if ($ttlSeconds < 300 || $ttlSeconds > 604800) {
            throw new RegistrationException('Email verification TTL is invalid.');
        }

        $token = self::base64Url(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $now = $now->setTimezone(new DateTimeZone('UTC'));
        $expires = $now->add(new DateInterval('PT' . $ttlSeconds . 'S'));
        $affected = $this->database->execute(new CompiledQuery(
            'INSERT INTO `forwext_email_verification_tokens` '
            . '(`token_hash`, `user_id`, `target_status`, `expires_at_utc`, `created_at_utc`) '
            . 'VALUES (:token_hash, :user_id, :target_status, :expires_at_utc, :created_at_utc)',
            [
                'token_hash' => $tokenHash,
                'user_id' => $userId->value(),
                'target_status' => $targetStatus->value,
                'expires_at_utc' => $expires->format('Y-m-d H:i:s.u'),
                'created_at_utc' => $now->format('Y-m-d H:i:s.u'),
            ],
        ));
        if ($affected !== 1) {
            throw new RegistrationException('Email verification token was not persisted.');
        }
        return $token;
    }

    public function consume(string $token, DateTimeImmutable $now): ?EmailVerificationGrant
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) !== 1) {
            return null;
        }
        $hash = hash('sha256', $token);
        $nowUtc = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');

        return $this->database->transaction(function () use ($hash, $nowUtc): ?EmailVerificationGrant {
            $row = $this->database->fetchOne(new CompiledQuery(
                'SELECT `user_id`, `target_status`, `expires_at_utc`, `consumed_at_utc` '
                . 'FROM `forwext_email_verification_tokens` WHERE `token_hash` = :token_hash LIMIT 1 FOR UPDATE',
                ['token_hash' => $hash],
                requiresTransaction: true,
            ));
            if ($row === null || is_string($row['consumed_at_utc'] ?? null)) {
                return null;
            }
            $expires = $row['expires_at_utc'] ?? null;
            if (!is_string($expires) || $expires <= $nowUtc) {
                return null;
            }
            $userId = $row['user_id'] ?? null;
            $targetStatus = $row['target_status'] ?? null;
            if (!is_string($userId) || !is_string($targetStatus)) {
                throw new RegistrationException('Email verification token row is malformed.');
            }

            $affected = $this->database->execute(new CompiledQuery(
                'UPDATE `forwext_email_verification_tokens` SET `consumed_at_utc` = :consumed_at_utc '
                . 'WHERE `token_hash` = :token_hash AND `consumed_at_utc` IS NULL',
                ['consumed_at_utc' => $nowUtc, 'token_hash' => $hash],
            ));
            if ($affected !== 1) {
                return null;
            }
            return new EmailVerificationGrant(UserId::fromStored($userId), UserStatus::from($targetStatus));
        });
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
