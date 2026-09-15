<?php

declare(strict_types=1);

namespace Forwext\Core\Profile\Url;

use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;

final readonly class ProfileUrlResolution
{
    public function __construct(
        public EntityId $userId,
        public ProfileSlug $requestedSlug,
        public ProfileSlug $currentSlug,
        public bool $isCurrent,
    ) {
        UserId::assert($userId);
    }
}
