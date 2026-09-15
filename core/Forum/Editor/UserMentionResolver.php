<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Editor;

use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Routing\BasePath;

final readonly class UserMentionResolver implements MentionResolver
{
    public function __construct(
        private UserRepository $users,
        private BasePath $basePath,
    ) {
    }

    public function resolve(EntityId $userId): ?MentionTarget
    {
        $user = $this->users->find($userId);
        if ($user === null) {
            return null;
        }

        $label = $user->username()->display();
        return new MentionTarget(
            '@' . $label,
            $this->basePath->prepend('/members/' . rawurlencode($label)),
        );
    }
}
