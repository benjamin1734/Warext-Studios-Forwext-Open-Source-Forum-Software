<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Mfa\Challenge;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Auth\Mfa\MfaException;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Infrastructure\Clock;
use Forwext\Core\Infrastructure\SystemClock;

final readonly class MfaChallengeStore
{
    public function __construct(private TransactionalQueryExecutor $database, private int $ttlSeconds = 300, private Clock $clock = new SystemClock())
    {
        if ($ttlSeconds < 60 || $ttlSeconds > 900) {
            throw new MfaException('MFA challenge TTL is invalid.');
        }
    }

    public function issue(EntityId $userId, string $deviceId, int $credentialVersion, MfaChallengePurpose $purpose, ?string $actionKey = null, bool $rememberRequested = false): string
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $deviceId) !== 1 || $credentialVersion < 1) {
            throw new MfaException('MFA challenge identity is invalid.');
        }
        if ($purpose === MfaChallengePurpose::SensitiveAction && ($actionKey === null || preg_match('/^[A-Za-z][A-Za-z0-9._:-]{0,190}$/D', $actionKey) !== 1)) {
            throw new MfaException('Sensitive-action challenge requires a valid action key.');
        }
        $raw = 'mfa_' . bin2hex(random_bytes(32));
        $expires = $this->clock->now()->add(new DateInterval('PT' . $this->ttlSeconds . 'S'));
        $this->database->execute(new CompiledQuery(
            'INSERT INTO `forwext_mfa_challenges` (`challenge_hash`,`user_id`,`device_id`,`credential_version`,`purpose`,`action_key`,`remember_requested`,`expires_at_utc`,`created_at_utc`,`consumed_at_utc`) '
            . 'VALUES (:challenge_hash,:user_id,:device_id,:credential_version,:purpose,:action_key,:remember_requested,:expires_at_utc,:created_at_utc,NULL)',
            ['challenge_hash' => hash('sha256', $raw), 'user_id' => $userId->value(), 'device_id' => $deviceId, 'credential_version' => $credentialVersion, 'purpose' => $purpose->value, 'action_key' => $actionKey, 'remember_requested' => $rememberRequested ? 1 : 0, 'expires_at_utc' => self::date($expires), 'created_at_utc' => self::date($this->clock->now())],
        ));
        return $raw;
    }

    public function consume(string $rawToken): ?MfaChallengeGrant
    {
        if (preg_match('/^mfa_[a-f0-9]{64}$/D', $rawToken) !== 1) {
            return null;
        }
        $hash = hash('sha256', $rawToken);
        return $this->database->transaction(function () use ($hash): ?MfaChallengeGrant {
            $row = $this->database->fetchOne(new CompiledQuery(
                'SELECT `user_id`,`device_id`,`credential_version`,`purpose`,`action_key`,`remember_requested`,`expires_at_utc`,`consumed_at_utc` FROM `forwext_mfa_challenges` WHERE `challenge_hash`=:challenge_hash LIMIT 1 FOR UPDATE',
                ['challenge_hash' => $hash],
                requiresTransaction: true,
            ));
            $grant = $this->hydrate($row);
            if ($grant === null) {
                return null;
            }
            return $this->database->execute(new CompiledQuery(
                'UPDATE `forwext_mfa_challenges` SET `consumed_at_utc`=:consumed_at_utc WHERE `challenge_hash`=:challenge_hash AND `consumed_at_utc` IS NULL',
                ['consumed_at_utc' => self::date($this->clock->now()), 'challenge_hash' => $hash],
            )) === 1 ? $grant : null;
        });
    }

    /** @param array<string,mixed>|null $row */
    private function hydrate(?array $row): ?MfaChallengeGrant
    {
        if ($row === null || ($row['consumed_at_utc'] ?? null) !== null || !is_string($row['expires_at_utc'] ?? null) || !is_string($row['purpose'] ?? null) || !is_string($row['user_id'] ?? null) || !is_string($row['device_id'] ?? null)) {
            return null;
        }
        $expires = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $row['expires_at_utc'], new DateTimeZone('UTC'));
        $purpose = MfaChallengePurpose::tryFrom($row['purpose']);
        if (!$expires instanceof DateTimeImmutable || $expires <= $this->clock->now() || $purpose === null) {
            return null;
        }
        return new MfaChallengeGrant(EntityId::fromString($row['user_id']), $row['device_id'], (int) ($row['credential_version'] ?? 0), $purpose, is_string($row['action_key'] ?? null) ? $row['action_key'] : null, (bool) ($row['remember_requested'] ?? false), $expires);
    }

    private static function date(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
