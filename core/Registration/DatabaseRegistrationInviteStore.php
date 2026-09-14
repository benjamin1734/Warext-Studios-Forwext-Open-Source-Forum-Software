<?php

declare(strict_types=1);

namespace Forwext\Core\Registration;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;

final readonly class DatabaseRegistrationInviteStore implements RegistrationInviteStore
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function issue(int $maxUses, ?DateTimeImmutable $expiresAt, DateTimeImmutable $now): string
    {
        if ($maxUses < 1 || $maxUses > 100000) {
            throw new RegistrationException('Registration invite max uses is invalid.');
        }
        $now = $now->setTimezone(new DateTimeZone('UTC'));
        $expiresAt = $expiresAt?->setTimezone(new DateTimeZone('UTC'));
        if ($expiresAt !== null && $expiresAt <= $now) {
            throw new RegistrationException('Registration invite expiry must be in the future.');
        }

        $code = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $affected = $this->database->execute(new CompiledQuery(
            'INSERT INTO `forwext_registration_invites` '
            . '(`invite_id`, `code_hash`, `uses`, `max_uses`, `expires_at_utc`, `disabled`, `created_at_utc`) '
            . 'VALUES (:invite_id, :code_hash, 0, :max_uses, :expires_at_utc, 0, :created_at_utc)',
            [
                'invite_id' => bin2hex(random_bytes(16)),
                'code_hash' => hash('sha256', $code),
                'max_uses' => $maxUses,
                'expires_at_utc' => $expiresAt?->format('Y-m-d H:i:s.u'),
                'created_at_utc' => $now->format('Y-m-d H:i:s.u'),
            ],
        ));
        if ($affected !== 1) {
            throw new RegistrationException('Registration invite was not persisted.');
        }
        return $code;
    }

    public function consume(string $code, DateTimeImmutable $now): bool
    {
        if (preg_match('/^[A-Za-z0-9_-]{8,128}$/D', $code) !== 1) {
            return false;
        }
        $hash = hash('sha256', $code);
        $nowUtc = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');

        return $this->database->transaction(function () use ($hash, $nowUtc): bool {
            $row = $this->database->fetchOne(new CompiledQuery(
                'SELECT `invite_id`, `uses`, `max_uses`, `expires_at_utc`, `disabled` '
                . 'FROM `forwext_registration_invites` WHERE `code_hash` = :code_hash LIMIT 1 FOR UPDATE',
                ['code_hash' => $hash],
                requiresTransaction: true,
            ));
            if ($row === null || (int) ($row['disabled'] ?? 1) !== 0) {
                return false;
            }
            $uses = (int) ($row['uses'] ?? -1);
            $maxUses = (int) ($row['max_uses'] ?? 0);
            $expires = $row['expires_at_utc'] ?? null;
            if ($uses < 0 || $maxUses < 1 || $uses >= $maxUses) {
                return false;
            }
            if (is_string($expires) && $expires !== '' && $expires <= $nowUtc) {
                return false;
            }
            $inviteId = $row['invite_id'] ?? null;
            if (!is_string($inviteId) || preg_match('/^[a-f0-9]{32}$/D', $inviteId) !== 1) {
                throw new RegistrationException('Registration invite row is malformed.');
            }
            $affected = $this->database->execute(new CompiledQuery(
                'UPDATE `forwext_registration_invites` SET `uses` = `uses` + 1 '
                . 'WHERE `invite_id` = :invite_id AND `uses` = :expected_uses',
                ['invite_id' => $inviteId, 'expected_uses' => $uses],
            ));
            if ($affected !== 1) {
                throw new RegistrationException('Registration invite consumption lost its lock ownership.');
            }
            return true;
        });
    }
}
