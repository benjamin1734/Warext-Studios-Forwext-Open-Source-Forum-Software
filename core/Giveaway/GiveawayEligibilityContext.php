<?php

declare(strict_types=1);

namespace Forwext\Core\Giveaway;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserStatus;
use InvalidArgumentException;

final readonly class GiveawayEligibilityContext
{
    /** @var list<EntityId> */
    public array $roleIds;

    /** @param list<EntityId> $roleIds */
    public function __construct(
        public EntityId $userId,
        public UserStatus $status,
        public DateTimeImmutable $createdAt,
        public int $visiblePostCount,
        array $roleIds,
        public bool $referredQualified,
        public int $qualifiedReferralCount,
    ) {
        if ($this->visiblePostCount < 0 || $this->qualifiedReferralCount < 0) {
            throw new InvalidArgumentException('Giveaway eligibility counters cannot be negative.');
        }
        $roles = [];
        foreach ($roleIds as $roleId) {
            if (!$roleId instanceof EntityId) {
                throw new InvalidArgumentException('Giveaway eligibility roles must be entity ids.');
            }
            $roles[$roleId->value()] = $roleId;
        }
        $this->roleIds = array_values($roles);
    }
}
