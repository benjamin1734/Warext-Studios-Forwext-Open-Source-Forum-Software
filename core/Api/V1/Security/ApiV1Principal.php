<?php

declare(strict_types=1);

namespace Forwext\Core\Api\V1\Security;

use DateTimeImmutable;
use Forwext\Core\Api\V1\ApiV1Scope;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class ApiV1Principal
{
    /** @var list<ApiV1Scope> */
    public array $scopes;

    /** @param list<ApiV1Scope> $scopes */
    public function __construct(
        public EntityId $credentialId,
        public EntityId $userId,
        public ApiV1PrincipalType $type,
        array $scopes,
        public ?DateTimeImmutable $expiresAt = null,
    ) {
        UserId::assert($this->userId);
        $normalized = [];
        foreach ($scopes as $scope) {
            if (!$scope instanceof ApiV1Scope) {
                throw new InvalidArgumentException('API principal contains an invalid scope.');
            }
            $normalized[$scope->value] = $scope;
        }
        if ($normalized === []) {
            throw new InvalidArgumentException('API principal must contain at least one scope.');
        }
        ksort($normalized, SORT_STRING);
        $this->scopes = array_values($normalized);
    }

    public function hasScope(ApiV1Scope $scope): bool
    {
        foreach ($this->scopes as $candidate) {
            if ($candidate === $scope) {
                return true;
            }
        }

        return false;
    }
}
