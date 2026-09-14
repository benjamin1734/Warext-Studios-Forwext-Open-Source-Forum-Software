<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Login;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Auth\AuthException;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;

final readonly class DatabaseLoginHistoryRecorder implements LoginHistoryRecorder
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function record(
        ?EntityId $userId,
        string $identityFingerprint,
        string $ipFingerprint,
        string $deviceFingerprint,
        LoginOutcome $outcome,
        DateTimeImmutable $occurredAt,
    ): void {
        if ($userId !== null) {
            UserId::assert($userId);
        }
        foreach ([$identityFingerprint, $ipFingerprint, $deviceFingerprint] as $fingerprint) {
            if (preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
                throw new AuthException('Login history fingerprint is invalid.');
            }
        }
        $affected = $this->database->execute(new CompiledQuery(
            'INSERT INTO `forwext_login_history` '
            . '(`user_id`, `identity_fingerprint`, `ip_fingerprint`, `device_fingerprint`, `outcome`, `occurred_at_utc`) '
            . 'VALUES (:user_id, :identity_fingerprint, :ip_fingerprint, :device_fingerprint, :outcome, :occurred_at_utc)',
            [
                'user_id' => $userId?->value(),
                'identity_fingerprint' => $identityFingerprint,
                'ip_fingerprint' => $ipFingerprint,
                'device_fingerprint' => $deviceFingerprint,
                'outcome' => $outcome->value,
                'occurred_at_utc' => $occurredAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
            ],
        ));
        if ($affected !== 1) {
            throw new AuthException('Login history record was not persisted.');
        }
    }
}
