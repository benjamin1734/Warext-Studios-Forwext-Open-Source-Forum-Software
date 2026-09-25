<?php

declare(strict_types=1);

namespace Forwext\Core\Api\V1\Security;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Api\V1\ApiV1Scope;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class ApiV1CredentialRecord
{
    /** @var list<ApiV1Scope> */
    public array $scopes;
    public DateTimeImmutable $createdAt;
    public ?DateTimeImmutable $expiresAt;
    public ?DateTimeImmutable $revokedAt;
    public ?DateTimeImmutable $lastUsedAt;

    /** @param list<ApiV1Scope> $scopes */
    public function __construct(
        public EntityId $credentialId,
        public EntityId $ownerUserId,
        public ApiV1PrincipalType $type,
        public string $displayName,
        public string $secretHash,
        array $scopes,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $expiresAt = null,
        ?DateTimeImmutable $revokedAt = null,
        ?DateTimeImmutable $lastUsedAt = null,
    ) {
        UserId::assert($this->ownerUserId);
        if (
            trim($this->displayName) === ''
            || strlen($this->displayName) > 100
            || preg_match('//u', $this->displayName) !== 1
        ) {
            throw new InvalidArgumentException('API credential display name is invalid.');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $this->secretHash) !== 1) {
            throw new InvalidArgumentException('API credential secret hash is invalid.');
        }

        $normalized = [];
        foreach ($scopes as $scope) {
            if (!$scope instanceof ApiV1Scope) {
                throw new InvalidArgumentException('API credential contains an invalid scope.');
            }
            $normalized[$scope->value] = $scope;
        }
        if ($normalized === []) {
            throw new InvalidArgumentException('API credential must contain at least one scope.');
        }
        ksort($normalized, SORT_STRING);
        $this->scopes = array_values($normalized);

        $utc = new DateTimeZone('UTC');
        $this->createdAt = $createdAt->setTimezone($utc);
        $this->expiresAt = $expiresAt?->setTimezone($utc);
        $this->revokedAt = $revokedAt?->setTimezone($utc);
        $this->lastUsedAt = $lastUsedAt?->setTimezone($utc);
        if ($this->expiresAt !== null && $this->expiresAt <= $this->createdAt) {
            throw new InvalidArgumentException('API credential expiry must be after creation.');
        }
    }

    public function activeAt(DateTimeImmutable $at): bool
    {
        $at = $at->setTimezone(new DateTimeZone('UTC'));

        return $this->revokedAt === null
            && ($this->expiresAt === null || $this->expiresAt > $at);
    }

    public function principal(): ApiV1Principal
    {
        return new ApiV1Principal(
            $this->credentialId,
            $this->ownerUserId,
            $this->type,
            $this->scopes,
            $this->expiresAt,
        );
    }
}
