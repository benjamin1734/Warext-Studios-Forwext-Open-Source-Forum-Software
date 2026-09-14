<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Device;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Auth\AuthException;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;

final readonly class DatabaseDeviceRepository implements DeviceRepository
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function touch(
        EntityId $userId,
        ?string $presentedDeviceId,
        string $userAgentFingerprint,
        string $ipFingerprint,
        DateTimeImmutable $now,
    ): DeviceRecord {
        UserId::assert($userId);
        self::assertFingerprint($userAgentFingerprint);
        self::assertFingerprint($ipFingerprint);
        $now = self::utc($now);

        return $this->database->transaction(function () use (
            $userId,
            $presentedDeviceId,
            $userAgentFingerprint,
            $ipFingerprint,
            $now,
        ): DeviceRecord {
            if ($presentedDeviceId !== null && preg_match('/^[a-f0-9]{32}$/D', $presentedDeviceId) === 1) {
                $row = $this->database->fetchOne(new CompiledQuery(
                    'SELECT `user_id`, `first_seen_at_utc`, `revoked_at_utc` FROM `forwext_user_devices` '
                    . 'WHERE `device_id` = :device_id LIMIT 1 FOR UPDATE',
                    ['device_id' => $presentedDeviceId],
                    requiresTransaction: true,
                ));
                if ($row !== null
                    && is_string($row['user_id'] ?? null)
                    && hash_equals($userId->value(), $row['user_id'])
                    && ($row['revoked_at_utc'] ?? null) === null
                    && is_string($row['first_seen_at_utc'] ?? null)
                ) {
                    $affected = $this->database->execute(new CompiledQuery(
                        'UPDATE `forwext_user_devices` SET `user_agent_fingerprint` = :user_agent_fingerprint, '
                        . '`last_ip_fingerprint` = :last_ip_fingerprint, `last_seen_at_utc` = :last_seen_at_utc '
                        . 'WHERE `device_id` = :device_id AND `user_id` = :user_id AND `revoked_at_utc` IS NULL',
                        [
                            'user_agent_fingerprint' => $userAgentFingerprint,
                            'last_ip_fingerprint' => $ipFingerprint,
                            'last_seen_at_utc' => self::formatDate($now),
                            'device_id' => $presentedDeviceId,
                            'user_id' => $userId->value(),
                        ],
                    ));
                    if ($affected !== 1) {
                        throw new AuthException('Authentication device update lost row ownership.');
                    }
                    return new DeviceRecord(
                        $presentedDeviceId,
                        $userId,
                        $userAgentFingerprint,
                        $ipFingerprint,
                        self::parseDate($row['first_seen_at_utc']),
                        $now,
                    );
                }
            }

            $deviceId = bin2hex(random_bytes(16));
            $affected = $this->database->execute(new CompiledQuery(
                'INSERT INTO `forwext_user_devices` '
                . '(`device_id`, `user_id`, `user_agent_fingerprint`, `last_ip_fingerprint`, '
                . '`first_seen_at_utc`, `last_seen_at_utc`, `revoked_at_utc`) '
                . 'VALUES (:device_id, :user_id, :user_agent_fingerprint, :last_ip_fingerprint, '
                . ':first_seen_at_utc, :last_seen_at_utc, NULL)',
                [
                    'device_id' => $deviceId,
                    'user_id' => $userId->value(),
                    'user_agent_fingerprint' => $userAgentFingerprint,
                    'last_ip_fingerprint' => $ipFingerprint,
                    'first_seen_at_utc' => self::formatDate($now),
                    'last_seen_at_utc' => self::formatDate($now),
                ],
            ));
            if ($affected !== 1) {
                throw new AuthException('Authentication device creation failed.');
            }
            return new DeviceRecord($deviceId, $userId, $userAgentFingerprint, $ipFingerprint, $now, $now);
        });
    }

    public function revoke(EntityId $userId, string $deviceId, DateTimeImmutable $now): bool
    {
        UserId::assert($userId);
        if (preg_match('/^[a-f0-9]{32}$/D', $deviceId) !== 1) {
            return false;
        }
        return $this->database->execute(new CompiledQuery(
            'UPDATE `forwext_user_devices` SET `revoked_at_utc` = :revoked_at_utc '
            . 'WHERE `device_id` = :device_id AND `user_id` = :user_id AND `revoked_at_utc` IS NULL',
            [
                'revoked_at_utc' => self::formatDate($now),
                'device_id' => $deviceId,
                'user_id' => $userId->value(),
            ],
        )) === 1;
    }

    private static function assertFingerprint(string $value): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new AuthException('Authentication device fingerprint is invalid.');
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
            throw new AuthException('Authentication device timestamp is invalid.');
        }
        return $date;
    }
}
