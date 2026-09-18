<?php

declare(strict_types=1);

namespace Forwext\Core\Faq\Search;

use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Search\Access\SearchAccessScopeProvider;

final readonly class FaqSearchAccessScopeProvider implements SearchAccessScopeProvider
{
    public const MEMBERS = 'faq.members';
    public const STAFF = 'faq.staff';

    public function __construct(private PermissionAuthorizer $authorizer)
    {
    }

    public function scopes(EntityId $userId): array
    {
        $scopes = [];
        if ($this->authorizer->allows($userId, PermissionKey::fromString('faq.view'))
            || $this->authorizer->allows($userId, PermissionKey::fromString('faq.manage'))
        ) {
            $scopes[] = self::MEMBERS;
        }
        if ($this->authorizer->allows($userId, PermissionKey::fromString('faq.manage'))) {
            $scopes[] = self::STAFF;
        }
        return $scopes;
    }
}
