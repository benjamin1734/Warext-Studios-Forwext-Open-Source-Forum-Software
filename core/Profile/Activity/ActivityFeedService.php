<?php

declare(strict_types=1);

namespace Forwext\Core\Profile\Activity;

use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Social\Interaction\SocialInteractionRepository;

final readonly class ActivityFeedService
{
    public function __construct(
        private ActivityFeedRepository $feed,
        private ProfileActivityRepository $profiles,
        private ProfileActivityService $profileService,
        private SocialInteractionRepository $social,
        private PermissionAuthorizer $authorizer,
    ) {
    }

    /** @return list<ActivityFeedEntry> */
    public function feed(EntityId $viewerId, int $limit = 50, int $offset = 0): array
    {
        if ($limit < 1 || $limit > 100 || $offset < 0 || $offset > 1_000_000) {
            throw new ProfileActivityException('Activity feed pagination is invalid.');
        }
        $ignored = [];
        foreach ($this->social->ignoredUserIds($viewerId) as $id) $ignored[$id->value()] = true;
        $gate = new PermissionGate($this->authorizer, $viewerId);
        $candidates = $this->feed->candidates(min(300, max($limit, $limit * 4)), $offset);
        $visible = [];
        foreach ($candidates as $entry) {
            if ($entry->actorUserId !== null && isset($ignored[$entry->actorUserId->value()])) continue;
            if ($entry->forumNodeId !== null) {
                if (!$gate->allows(PermissionKey::fromString('forum.view'), $entry->forumNodeId)) continue;
            } elseif ($entry->profileOwnerUserId !== null) {
                if (!$this->profileService->canViewProfile($viewerId, $entry->profileOwnerUserId)) continue;
                if ($entry->profilePostId !== null) {
                    $post = $this->profiles->findPost($entry->profilePostId);
                    if ($post === null || $post->isDeleted() || $post->moderationState !== ProfileActivityModerationState::Visible) continue;
                }
            } else {
                continue;
            }
            $visible[] = $entry;
            if (count($visible) >= $limit) break;
        }
        return $visible;
    }
}
