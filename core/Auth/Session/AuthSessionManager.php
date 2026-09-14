<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Session;

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
        $payload = json_encode([
            'schema' => 1,
            'user_id' => $userId->value(),
            'device_id' => $deviceId,
            'credential_version' => $credentialVersion,
            'issued_at' => $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->sessions->write($sessionId, $payload, $this->ttlSeconds);
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
            } catch (\Throwable $exception) {
                $this->sessions->delete($newSessionId);
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
            return null;
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
    }

    private static function newSessionId(): string
    {
        return 's_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
