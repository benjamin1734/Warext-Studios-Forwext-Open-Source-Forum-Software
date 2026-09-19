<?php

declare(strict_types=1);

namespace Forwext\Core\Portfolio\Search;

use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Search\Access\SearchAccessScopeProvider;

final readonly class PortfolioSearchAccessScopeProvider implements SearchAccessScopeProvider
{
    public const MEMBERS = 'portfolio.members';

    public function __construct(private PermissionAuthorizer $authorizer)
    {
    }

    public function scopes(EntityId $userId): array
    {
        return $this->authorizer->allows($userId, PermissionKey::fromString('portfolio.view'))
            || $this->authorizer->allows($userId, PermissionKey::fromString('portfolio.manage_all'))
            ? [self::MEMBERS]
            : [];
    }
}
