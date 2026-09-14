<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Remember;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Auth\AuthException;
use Forwext\Core\Auth\Credential\CredentialStore;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;

final readonly class RememberTokenService
{
    public function __construct(
        private TransactionalQueryExecutor $database,
        private CredentialStore $credentials,
        private int $ttlSeconds = 2592000,
    ) {
        if ($ttlSeconds < 3600 || $ttlSeconds > 7776000) {
            throw new AuthException('Remember-token TTL is outside safe bounds.');
        }
    }

    public function issue(
        EntityId $userId,
        string $deviceId,
        int $credentialVersion,
        DateTimeImmutable $now,
    ): string {
        UserId::assert($userId);
        if (preg_match('/^[a-f0-9]{32}$/D', $deviceId) !== 1 || $credentialVersion < 1) {
            throw new AuthException('Remember-token issuance input is invalid.');
        }
        $credential = $this->credentials->find($userId);
        if ($credential === null || $credential->version !== $credentialVersion) {
            throw new AuthException('Remember-token credential version is stale.');
        }

        [$raw, $selectorHash, $validatorHash] = self::newToken();
        $familyId = bin2hex(random_bytes(16));
        $now = self::utc($now);
        $expires = $now->add(new DateInterval('PT' . $this->ttlSeconds . 'S'));
        $this->insertToken(
            $selectorHash,
            $validatorHash,
            $familyId,
            $userId,
            $deviceId,
            $credentialVersion,
            $expires,
            $now,
        );
        return $raw;
    }

    public function consumeAndRotate(string $rawToken, DateTimeImmutable $now): ?RememberTokenGrant
    {
        $parts = explode('.', $rawToken, 2);
        if (count($parts) !== 2
            || preg_match('/^[A-Za-z0-9_-]{22}$/D', $parts[0]) !== 1
            || preg_match('/^[A-Za-z0-9_-]{43}$/D', $parts[1]) !== 1
        ) {
            return null;
        }
        $selectorHash = hash('sha256', $parts[0]);
        $validatorHash = hash('sha256', $parts[1]);
        $now = self::utc($now);
        $nowString = self::formatDate($now);

        return $this->database->transaction(function () use (
            $selectorHash,
            $validatorHash,
            $now,
            $nowString,
        ): ?RememberTokenGrant {
            $row = $this->database->fetchOne(new CompiledQuery(
                'SELECT `validator_hash`, `family_id`, `user_id`, `device_id`, `credential_version`, '
                . '`expires_at_utc`, `consumed_at_utc`, `revoked_at_utc` '
                . 'FROM `forwext_remember_tokens` WHERE `selector_hash` = :selector_hash LIMIT 1 FOR UPDATE',
                ['selector_hash' => $selectorHash],
                requiresTransaction: true,
            ));
            if ($row === null) {
                return null;
            }
            $familyId = $row['family_id'] ?? null;
            if (!is_string($familyId) || preg_match('/^[a-f0-9]{32}$/D', $familyId) !== 1) {
                throw new AuthException('Remember-token row is malformed.');
            }
            if (($row['consumed_at_utc'] ?? null) !== null || ($row['revoked_at_utc'] ?? null) !== null) {
                $this->revokeFamily($familyId, $now);
                return null;
            }

            $storedValidator = $row['validator_hash'] ?? null;
            $expiresRaw = $row['expires_at_utc'] ?? null;
            $userRaw = $row['user_id'] ?? null;
            $deviceId = $row['device_id'] ?? null;
            $version = (int) ($row['credential_version'] ?? 0);
            if (!is_string($storedValidator)
                || !is_string($expiresRaw)
                || !is_string($userRaw)
                || !is_string($deviceId)
                || preg_match('/^[a-f0-9]{64}$/D', $storedValidator) !== 1
                || preg_match('/^[a-f0-9]{32}$/D', $deviceId) !== 1
                || $version < 1
            ) {
                throw new AuthException('Remember-token row is malformed.');
            }
            if (!hash_equals($storedValidator, $validatorHash)) {
                $this->revokeFamily($familyId, $now);
                return null;
            }
            $expires = self::parseDate($expiresRaw);
            if ($expires <= $now) {
                $this->revokeFamily($familyId, $now);
                return null;
            }

            $userId = UserId::fromStored($userRaw);
            $credential = $this->credentials->find($userId);
            if ($credential === null || $credential->version !== $version) {
                $this->revokeFamily($familyId, $now);
                return null;
            }

            [$replacementRaw, $replacementSelectorHash, $replacementValidatorHash] = self::newToken();
            $affected = $this->database->execute(new CompiledQuery(
                'UPDATE `forwext_remember_tokens` SET `consumed_at_utc` = :consumed_at_utc, '
                . '`replacement_selector_hash` = :replacement_selector_hash '
                . 'WHERE `selector_hash` = :selector_hash AND `consumed_at_utc` IS NULL AND `revoked_at_utc` IS NULL',
                [
                    'consumed_at_utc' => $nowString,
                    'replacement_selector_hash' => $replacementSelectorHash,
                    'selector_hash' => $selectorHash,
                ],
            ));
            if ($affected !== 1) {
                $this->revokeFamily($familyId, $now);
                return null;
            }
            $this->insertToken(
                $replacementSelectorHash,
                $replacementValidatorHash,
                $familyId,
                $userId,
                $deviceId,
                $version,
                $expires,
                $now,
            );

            return new RememberTokenGrant($userId, $deviceId, $version, $replacementRaw);
        });
    }

    public function revokeUser(EntityId $userId, DateTimeImmutable $now): int
    {
        UserId::assert($userId);
        return $this->database->execute(new CompiledQuery(
            'UPDATE `forwext_remember_tokens` SET `revoked_at_utc` = :revoked_at_utc '
            . 'WHERE `user_id` = :user_id AND `revoked_at_utc` IS NULL',
            ['revoked_at_utc' => self::formatDate($now), 'user_id' => $userId->value()],
        ));
    }

    private function revokeFamily(string $familyId, DateTimeImmutable $now): void
    {
        $this->database->execute(new CompiledQuery(
            'UPDATE `forwext_remember_tokens` SET `revoked_at_utc` = :revoked_at_utc '
            . 'WHERE `family_id` = :family_id AND `revoked_at_utc` IS NULL',
            ['revoked_at_utc' => self::formatDate($now), 'family_id' => $familyId],
        ));
    }

    private function insertToken(
        string $selectorHash,
        string $validatorHash,
        string $familyId,
        EntityId $userId,
        string $deviceId,
        int $credentialVersion,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $createdAt,
    ): void {
        $affected = $this->database->execute(new CompiledQuery(
            'INSERT INTO `forwext_remember_tokens` '
            . '(`selector_hash`, `validator_hash`, `family_id`, `user_id`, `device_id`, `credential_version`, '
            . '`expires_at_utc`, `created_at_utc`, `consumed_at_utc`, `replacement_selector_hash`, `revoked_at_utc`) '
            . 'VALUES (:selector_hash, :validator_hash, :family_id, :user_id, :device_id, :credential_version, '
            . ':expires_at_utc, :created_at_utc, NULL, NULL, NULL)',
            [
                'selector_hash' => $selectorHash,
                'validator_hash' => $validatorHash,
                'family_id' => $familyId,
                'user_id' => $userId->value(),
                'device_id' => $deviceId,
                'credential_version' => $credentialVersion,
                'expires_at_utc' => self::formatDate($expiresAt),
                'created_at_utc' => self::formatDate($createdAt),
            ],
        ));
        if ($affected !== 1) {
            throw new AuthException('Remember token was not persisted.');
        }
    }

    /** @return array{string, string, string} */
    private static function newToken(): array
    {
        $selector = self::base64Url(random_bytes(16));
        $validator = self::base64Url(random_bytes(32));
        return [$selector . '.' . $validator, hash('sha256', $selector), hash('sha256', $validator)];
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
            throw new AuthException('Remember-token timestamp is invalid.');
        }
        return $date;
    }
}
