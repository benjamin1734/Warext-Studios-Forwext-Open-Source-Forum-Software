<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Mfa\Passkey;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Auth\Mfa\MfaException;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Infrastructure\Clock;
use Forwext\Core\Infrastructure\SystemClock;

final readonly class DatabasePasskeyService
{
    public function __construct(
        private TransactionalQueryExecutor $database,
        private WebAuthnEngine $engine,
        private int $ceremonyTtlSeconds = 300,
        private Clock $clock = new SystemClock(),
    ) {
        if ($ceremonyTtlSeconds < 60 || $ceremonyTtlSeconds > 600) {
            throw new MfaException('Passkey ceremony TTL is invalid.');
        }
    }

    public function beginRegistration(EntityId $userId, string $username, string $displayName): PasskeyCeremony
    {
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT `credential_id_b64url` FROM `forwext_passkey_credentials` WHERE `user_id` = :user_id AND `revoked_at_utc` IS NULL',
            ['user_id' => $userId->value()],
        ));
        $excluded = [];
        foreach ($rows as $row) {
            if (!is_string($row['credential_id_b64url'] ?? null)) {
                throw new MfaException('Stored passkey credential id is malformed.');
            }
            $excluded[] = self::base64UrlDecode($row['credential_id_b64url']);
        }
        $options = $this->engine->registrationOptions($userId->value(), $username, $displayName, $excluded);
        return $this->storeCeremony($userId, PasskeyCeremonyPurpose::Registration, $options);
    }

    public function completeRegistration(EntityId $userId, string $ceremonyToken, string $responseJson, string $label): void
    {
        $label = trim($label);
        if ($label === '' || strlen($label) > 191 || str_contains($label, "\0")) {
            throw new MfaException('Passkey label is invalid.');
        }
        $options = $this->consumeCeremony($userId, $ceremonyToken, PasskeyCeremonyPurpose::Registration);
        $verified = $this->engine->verifyRegistration($responseJson, $options);
        $encodedId = self::base64UrlEncode($verified->credentialId);
        $affected = $this->database->execute(new CompiledQuery(
            'INSERT INTO `forwext_passkey_credentials` '
            . '(`credential_hash`,`credential_id_b64url`,`user_id`,`credential_record_json`,`label`,`created_at_utc`,`last_used_at_utc`,`revoked_at_utc`) '
            . 'VALUES (:credential_hash,:credential_id_b64url,:user_id,:credential_record_json,:label,:created_at_utc,NULL,NULL)',
            [
                'credential_hash' => hash('sha256', $verified->credentialId),
                'credential_id_b64url' => $encodedId,
                'user_id' => $userId->value(),
                'credential_record_json' => $verified->credentialRecordJson,
                'label' => $label,
                'created_at_utc' => self::date($this->clock->now()),
            ],
        ));
        if ($affected !== 1) {
            throw new MfaException('Passkey credential could not be persisted.');
        }
    }

    public function beginAuthentication(EntityId $userId): PasskeyCeremony
    {
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT `credential_record_json` FROM `forwext_passkey_credentials` WHERE `user_id` = :user_id AND `revoked_at_utc` IS NULL ORDER BY `created_at_utc` ASC',
            ['user_id' => $userId->value()],
        ));
        $records = [];
        foreach ($rows as $row) {
            if (!is_string($row['credential_record_json'] ?? null)) {
                throw new MfaException('Stored passkey credential record is malformed.');
            }
            $records[] = $row['credential_record_json'];
        }
        return $this->storeCeremony(
            $userId,
            PasskeyCeremonyPurpose::Authentication,
            $this->engine->authenticationOptions($records),
        );
    }

    public function completeAuthentication(EntityId $userId, string $ceremonyToken, string $responseJson): bool
    {
        $options = $this->consumeCeremony($userId, $ceremonyToken, PasskeyCeremonyPurpose::Authentication);
        $credentialId = $this->engine->credentialIdFromResponse($responseJson);
        $hash = hash('sha256', $credentialId);

        return $this->database->transaction(function () use ($userId, $responseJson, $options, $hash): bool {
            $row = $this->database->fetchOne(new CompiledQuery(
                'SELECT `credential_record_json`,`revoked_at_utc` FROM `forwext_passkey_credentials` '
                . 'WHERE `credential_hash` = :credential_hash AND `user_id` = :user_id LIMIT 1 FOR UPDATE',
                ['credential_hash' => $hash, 'user_id' => $userId->value()],
                requiresTransaction: true,
            ));
            if ($row === null || ($row['revoked_at_utc'] ?? null) !== null || !is_string($row['credential_record_json'] ?? null)) {
                return false;
            }
            $updated = $this->engine->verifyAuthentication(
                $responseJson,
                $options,
                $row['credential_record_json'],
                $userId->value(),
            );
            return $this->database->execute(new CompiledQuery(
                'UPDATE `forwext_passkey_credentials` SET `credential_record_json` = :credential_record_json, '
                . '`last_used_at_utc` = :last_used_at_utc WHERE `credential_hash` = :credential_hash AND `user_id` = :user_id',
                [
                    'credential_record_json' => $updated,
                    'last_used_at_utc' => self::date($this->clock->now()),
                    'credential_hash' => $hash,
                    'user_id' => $userId->value(),
                ],
            )) === 1;
        });
    }

    public function activeCount(EntityId $userId): int
    {
        return max(0, (int) $this->database->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `forwext_passkey_credentials` WHERE `user_id` = :user_id AND `revoked_at_utc` IS NULL',
            ['user_id' => $userId->value()],
        )));
    }

    private function storeCeremony(EntityId $userId, PasskeyCeremonyPurpose $purpose, string $options): PasskeyCeremony
    {
        $raw = 'wa_' . bin2hex(random_bytes(32));
        $expires = $this->clock->now()->add(new DateInterval('PT' . $this->ceremonyTtlSeconds . 'S'));
        $this->database->execute(new CompiledQuery(
            'INSERT INTO `forwext_webauthn_ceremonies` (`ceremony_hash`,`user_id`,`purpose`,`options_json`,`expires_at_utc`,`created_at_utc`,`consumed_at_utc`) '
            . 'VALUES (:ceremony_hash,:user_id,:purpose,:options_json,:expires_at_utc,:created_at_utc,NULL)',
            [
                'ceremony_hash' => hash('sha256', $raw),
                'user_id' => $userId->value(),
                'purpose' => $purpose->value,
                'options_json' => $options,
                'expires_at_utc' => self::date($expires),
                'created_at_utc' => self::date($this->clock->now()),
            ],
        ));
        return new PasskeyCeremony($raw, $options);
    }

    private function consumeCeremony(EntityId $userId, string $raw, PasskeyCeremonyPurpose $purpose): string
    {
        if (preg_match('/^wa_[a-f0-9]{64}$/D', $raw) !== 1) {
            throw new MfaException('Passkey ceremony is invalid or expired.');
        }
        $hash = hash('sha256', $raw);
        return $this->database->transaction(function () use ($userId, $purpose, $hash): string {
            $row = $this->database->fetchOne(new CompiledQuery(
                'SELECT `options_json`,`expires_at_utc`,`consumed_at_utc` FROM `forwext_webauthn_ceremonies` '
                . 'WHERE `ceremony_hash` = :ceremony_hash AND `user_id` = :user_id AND `purpose` = :purpose LIMIT 1 FOR UPDATE',
                ['ceremony_hash' => $hash, 'user_id' => $userId->value(), 'purpose' => $purpose->value],
                requiresTransaction: true,
            ));
            if ($row === null || ($row['consumed_at_utc'] ?? null) !== null || !is_string($row['options_json'] ?? null) || !is_string($row['expires_at_utc'] ?? null)) {
                throw new MfaException('Passkey ceremony is invalid or expired.');
            }
            $expires = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $row['expires_at_utc'], new DateTimeZone('UTC'));
            if (!$expires instanceof DateTimeImmutable || $expires <= $this->clock->now()) {
                throw new MfaException('Passkey ceremony is invalid or expired.');
            }
            if ($this->database->execute(new CompiledQuery(
                'UPDATE `forwext_webauthn_ceremonies` SET `consumed_at_utc` = :consumed_at_utc '
                . 'WHERE `ceremony_hash` = :ceremony_hash AND `consumed_at_utc` IS NULL',
                ['consumed_at_utc' => self::date($this->clock->now()), 'ceremony_hash' => $hash],
            )) !== 1) {
                throw new MfaException('Passkey ceremony was already consumed.');
            }
            return $row['options_json'];
        });
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
            throw new MfaException('Stored passkey credential id is invalid.');
        }
        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);
        if ($decoded === false || $decoded === '') {
            throw new MfaException('Stored passkey credential id is invalid.');
        }
        return $decoded;
    }

    private static function date(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
