<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Mfa\TrustedDevice;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Auth\Mfa\MfaException;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Infrastructure\Clock;
use Forwext\Core\Infrastructure\SystemClock;

final readonly class TrustedDeviceService
{
    public function __construct(private TransactionalQueryExecutor $database, private int $ttlSeconds = 2592000, private Clock $clock = new SystemClock())
    {
        if ($ttlSeconds < 300 || $ttlSeconds > 31536000) {
            throw new MfaException('Trusted-device TTL is invalid.');
        }
    }

    public function issue(EntityId $userId, string $deviceId, int $credentialVersion): TrustedDeviceGrant
    {
        self::assertDevice($deviceId, $credentialVersion);
        $raw = 'td_' . bin2hex(random_bytes(32));
        $expires = $this->clock->now()->add(new DateInterval('PT' . $this->ttlSeconds . 'S'));
        $this->database->execute(new CompiledQuery(
            'INSERT INTO `forwext_trusted_devices` (`token_hash`,`user_id`,`device_id`,`credential_version`,`expires_at_utc`,`created_at_utc`,`last_used_at_utc`,`revoked_at_utc`) '
            . 'VALUES (:token_hash,:user_id,:device_id,:credential_version,:expires_at_utc,:created_at_utc,NULL,NULL)',
            ['token_hash' => hash('sha256', $raw), 'user_id' => $userId->value(), 'device_id' => $deviceId, 'credential_version' => $credentialVersion, 'expires_at_utc' => self::date($expires), 'created_at_utc' => self::date($this->clock->now())],
        ));
        return new TrustedDeviceGrant($raw, $expires);
    }

    public function validate(EntityId $userId, string $deviceId, int $credentialVersion, string $rawToken): bool
    {
        self::assertDevice($deviceId, $credentialVersion);
        if (preg_match('/^td_[a-f0-9]{64}$/D', $rawToken) !== 1) {
            return false;
        }
        $hash = hash('sha256', $rawToken);
        return $this->database->transaction(function () use ($userId, $deviceId, $credentialVersion, $hash): bool {
            $row = $this->database->fetchOne(new CompiledQuery(
                'SELECT `expires_at_utc`,`revoked_at_utc` FROM `forwext_trusted_devices` WHERE `token_hash`=:token_hash AND `user_id`=:user_id AND `device_id`=:device_id AND `credential_version`=:credential_version LIMIT 1 FOR UPDATE',
                ['token_hash' => $hash, 'user_id' => $userId->value(), 'device_id' => $deviceId, 'credential_version' => $credentialVersion],
                requiresTransaction: true,
            ));
            if ($row === null || ($row['revoked_at_utc'] ?? null) !== null || !is_string($row['expires_at_utc'] ?? null)) {
                return false;
            }
            $expires = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $row['expires_at_utc'], new DateTimeZone('UTC'));
            if (!$expires instanceof DateTimeImmutable || $expires <= $this->clock->now()) {
                return false;
            }
            $this->database->execute(new CompiledQuery('UPDATE `forwext_trusted_devices` SET `last_used_at_utc`=:last_used_at_utc WHERE `token_hash`=:token_hash', ['last_used_at_utc' => self::date($this->clock->now()), 'token_hash' => $hash]));
            return true;
        });
    }

    private static function assertDevice(string $deviceId, int $credentialVersion): void
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $deviceId) !== 1 || $credentialVersion < 1) {
            throw new MfaException('Trusted-device identity is invalid.');
        }
    }

    private static function date(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
