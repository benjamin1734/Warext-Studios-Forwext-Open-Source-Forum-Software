<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Session;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Auth\AuthException;
use Forwext\Core\Auth\Credential\CredentialStore;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Infrastructure\Clock;
use Forwext\Core\Infrastructure\SystemClock;
use Forwext\Core\Session\SessionStore;
use JsonException;

final readonly class AuthSessionManager
{
    public function __construct(
        private SessionStore $sessions,
        private CredentialStore $credentials,
        private int $ttlSeconds = 7200,
        private Clock $clock = new SystemClock(),
        private ?AuthSessionIndex $index = null,
    ) {
        if ($ttlSeconds < 300 || $ttlSeconds > 604800) {
            throw new AuthException('Authentication session TTL is outside safe bounds.');
        }
    }

    public function create(EntityId $userId, string $deviceId, int $credentialVersion): string
    {
        UserId::assert($userId);
        if (preg_match('/^[a-f0-9]{32}$/D', $deviceId) !== 1 || $credentialVersion < 1) {
            throw new AuthException('Authentication session input is invalid.');
        }
        $sessionId = self::newSessionId();
        $issuedAt = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $expiresAt = $issuedAt->add(new DateInterval('PT' . $this->ttlSeconds . 'S'));
        $payload = json_encode([
            'schema' => 1,
            'user_id' => $userId->value(),
            'device_id' => $deviceId,
            'credential_version' => $credentialVersion,
            'issued_at' => $issuedAt->format('Y-m-d\TH:i:s.u\Z'),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->sessions->write($sessionId, $payload, $this->ttlSeconds);

        if ($this->index !== null) {
            try {
                $this->index->register(new AuthSessionIndexRecord(
                    hash('sha256', $sessionId),
                    $userId,
                    $deviceId,
                    $credentialVersion,
                    $issuedAt,
                    $expiresAt,
                    $issuedAt,
                ));
            } catch (\Throwable $exception) {
                $this->sessions->delete($sessionId);
                throw new AuthException('Authentication session index registration failed.', previous: $exception);
            }
        }

        return $sessionId;
    }

    public function establish(
        EntityId $userId,
        string $deviceId,
        int $credentialVersion,
        ?string $previousSessionId = null,
    ): string {
        $newSessionId = $this->create($userId, $deviceId, $credentialVersion);
        if ($previousSessionId !== null && $previousSessionId !== $newSessionId) {
            try {
                $this->sessions->delete($previousSessionId);
                if ($this->index !== null) {
                    $this->index->revoke(hash('sha256', $previousSessionId), $this->clock->now());
                }
            } catch (\Throwable $exception) {
                $this->sessions->delete($newSessionId);
                if ($this->index !== null) {
                    $this->index->revoke(hash('sha256', $newSessionId), $this->clock->now());
                }
                throw new AuthException('Unable to replace the previous session.', previous: $exception);
            }
        }
        return $newSessionId;
    }

    public function resolve(string $sessionId): ?AuthSessionIdentity
    {
        $record = $this->sessions->read($sessionId);
        if ($record === null) {
            return null;
        }

        try {
            $data = json_decode($record->payload, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->sessions->delete($sessionId);
            return null;
        }
        if (!is_array($data)
            || ($data['schema'] ?? null) !== 1
            || !is_string($data['user_id'] ?? null)
            || !is_string($data['device_id'] ?? null)
            || !is_int($data['credential_version'] ?? null)
            || !is_string($data['issued_at'] ?? null)
        ) {
            $this->sessions->delete($sessionId);
            return null;
        }

        try {
            $userId = UserId::fromStored($data['user_id']);
            if (preg_match('/^[a-f0-9]{32}$/D', $data['device_id']) !== 1 || $data['credential_version'] < 1) {
                throw new AuthException('Authentication session payload is invalid.');
            }
            $issuedAt = DateTimeImmutable::createFromFormat(
                '!Y-m-d\TH:i:s.u\Z',
                $data['issued_at'],
                new DateTimeZone('UTC'),
            );
            if (!$issuedAt instanceof DateTimeImmutable || $issuedAt > $this->clock->now()) {
                throw new AuthException('Authentication session timestamp is invalid.');
            }
        } catch (\Throwable) {
            $this->sessions->delete($sessionId);
            return null;
        }

        $credential = $this->credentials->find($userId);
        if ($credential === null || $credential->version !== $data['credential_version']) {
            $this->sessions->delete($sessionId);
            if ($this->index !== null) {
                $this->index->revoke(hash('sha256', $sessionId), $this->clock->now());
            }
            return null;
        }

        if ($this->index !== null) {
            $sessionHash = hash('sha256', $sessionId);
            $indexed = $this->index->find($sessionHash);
            if ($indexed !== null) {
                if (
                    !$indexed->userId->equals($userId)
                    || !hash_equals($indexed->deviceId, $data['device_id'])
                    || $indexed->credentialVersion !== $data['credential_version']
                    || !$indexed->activeAt($this->clock->now())
                ) {
                    $this->sessions->delete($sessionId);
                    return null;
                }
            } else {
                // Once session indexing is enabled, unindexed legacy tokens fail closed.
                $this->sessions->delete($sessionId);
                return null;
            }
            $this->index->touch($sessionHash, $this->clock->now());
        }

        return new AuthSessionIdentity($userId, $data['device_id'], $data['credential_version'], $issuedAt);
    }

    public function rotate(string $currentSessionId): string
    {
        $identity = $this->resolve($currentSessionId);
        if ($identity === null) {
            throw new AuthException('Authentication session cannot be rotated.');
        }
        return $this->establish(
            $identity->userId,
            $identity->deviceId,
            $identity->credentialVersion,
            $currentSessionId,
        );
    }

    public function revoke(string $sessionId): void
    {
        $this->sessions->delete($sessionId);
        if ($this->index !== null) {
            $this->index->revoke(hash('sha256', $sessionId), $this->clock->now());
        }
    }

    private static function newSessionId(): string
    {
        return 's_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
