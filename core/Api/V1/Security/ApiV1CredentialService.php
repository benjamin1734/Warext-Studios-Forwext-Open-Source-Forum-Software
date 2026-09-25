<?php

declare(strict_types=1);

namespace Forwext\Core\Api\V1\Security;

use DateInterval;
use DateTimeImmutable;
use Forwext\Core\Api\V1\ApiV1Scope;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Infrastructure\Clock;
use Forwext\Core\Infrastructure\SystemClock;
use InvalidArgumentException;

final readonly class ApiV1CredentialService
{
    public function __construct(
        private ApiV1CredentialRepository $repository,
        private Clock $clock = new SystemClock(),
    ) {
    }

    /** @param list<ApiV1Scope> $scopes */
    public function issue(
        EntityId $ownerUserId,
        ApiV1PrincipalType $type,
        string $displayName,
        array $scopes,
        ?DateInterval $ttl = null,
    ): ApiV1IssuedCredential {
        UserId::assert($ownerUserId);
        $now = $this->clock->now();
        $expiresAt = $ttl === null ? null : $now->add($ttl);
        if ($expiresAt !== null && $expiresAt <= $now) {
            throw new InvalidArgumentException('API credential TTL must produce a future expiry.');
        }

        $secret = $type->secretPrefix() . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $record = new ApiV1CredentialRecord(
            EntityId::fromString(bin2hex(random_bytes(16))),
            $ownerUserId,
            $type,
            $displayName,
            hash('sha256', $secret),
            $scopes,
            $now,
            $expiresAt,
        );
        $this->repository->save($record);

        return new ApiV1IssuedCredential($secret, $record);
    }

    public function revoke(EntityId $ownerUserId, EntityId $credentialId): void
    {
        UserId::assert($ownerUserId);
        $this->repository->revoke($credentialId, $ownerUserId, $this->clock->now());
    }
}
