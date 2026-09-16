<?php

declare(strict_types=1);

namespace Forwext\Core\Social\Interaction;

use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Domain\User\UserStatus;
use Forwext\Core\Forum\Post\Post;
use Forwext\Core\Forum\Post\PostModerationState;
use Forwext\Core\Forum\Post\PostRepository;
use Forwext\Core\Forum\Thread\Thread;
use Forwext\Core\Forum\Thread\ThreadModerationState;
use Forwext\Core\Forum\Thread\ThreadRepository;

final readonly class SocialInteractionService
{
    public function __construct(
        private SocialInteractionRepository $interactions,
        private PostRepository $posts,
        private ThreadRepository $threads,
        private UserRepository $users,
        private PermissionAuthorizer $authorizer,
    ) {
    }

    public function react(EntityId $actorId, EntityId $postId, string $reactionKey): ReactionSummary
    {
        [$post, $thread, $gate] = $this->visiblePostContext($actorId, $postId);
        $gate->require(SocialInteractionPermission::React->key(), $thread->forumNodeId());
        if ($post->authorUserId()?->value() === $actorId->value()) {
            throw new SocialInteractionException('Users cannot react to their own posts.');
        }
        $type = $this->interactions->reactionType($reactionKey);
        if ($type === null) {
            throw new SocialInteractionException('Reaction type is unavailable.');
        }
        $this->interactions->setReaction($actorId, $post->id(), $type->key);
        return $this->interactions->reactionSummary($post->id());
    }

    public function removeReaction(EntityId $actorId, EntityId $postId): ReactionSummary
    {
        [, $thread, $gate] = $this->visiblePostContext($actorId, $postId);
        $gate->require(SocialInteractionPermission::React->key(), $thread->forumNodeId());
        $this->interactions->removeReaction($actorId, $postId);
        return $this->interactions->reactionSummary($postId);
    }

    public function reactionSummary(EntityId $actorId, EntityId $postId): ReactionSummary
    {
        [, $thread, $gate] = $this->visiblePostContext($actorId, $postId);
        $gate->require(PermissionKey::fromString('forum.view'), $thread->forumNodeId());
        return $this->interactions->reactionSummary($postId);
    }

    public function bookmark(EntityId $actorId, EntityId $postId, ?string $note): void
    {
        [, $thread, $gate] = $this->visiblePostContext($actorId, $postId);
        $gate->require(SocialInteractionPermission::Bookmark->key(), $thread->forumNodeId());
        $this->interactions->saveBookmark($actorId, $postId, $this->normalizeNote($note));
    }

    public function removeBookmark(EntityId $actorId, EntityId $postId): void
    {
        [, $thread, $gate] = $this->visiblePostContext($actorId, $postId);
        $gate->require(SocialInteractionPermission::Bookmark->key(), $thread->forumNodeId());
        $this->interactions->removeBookmark($actorId, $postId);
    }

    /** @return list<BookmarkEntry> */
    public function bookmarks(EntityId $actorId, int $limit = 50, int $offset = 0): array
    {
        $entries = $this->interactions->bookmarks($actorId, $limit, $offset);
        $visible = [];
        foreach ($entries as $entry) {
            try {
                [, $thread, $gate] = $this->visiblePostContext($actorId, $entry->postId);
                $gate->require(SocialInteractionPermission::Bookmark->key(), $thread->forumNodeId());
                $visible[] = $entry;
            } catch (SocialInteractionException|PermissionDeniedException) {
                // Private bookmark rows never disclose content that is no longer visible to the owner.
            }
        }
        return $visible;
    }

    public function follow(EntityId $actorId, EntityId $targetId): void
    {
        $this->globalGate($actorId)->require(SocialInteractionPermission::Follow->key());
        $this->requireTargetUser($actorId, $targetId);
        if ($this->interactions->isIgnoring($actorId, $targetId)) {
            throw new SocialInteractionException('Ignored users cannot be followed.');
        }
        $this->interactions->follow($actorId, $targetId);
    }

    public function unfollow(EntityId $actorId, EntityId $targetId): void
    {
        $this->globalGate($actorId)->require(SocialInteractionPermission::Follow->key());
        $this->requireTargetUser($actorId, $targetId);
        $this->interactions->unfollow($actorId, $targetId);
    }

    public function ignore(EntityId $actorId, EntityId $targetId): void
    {
        $this->globalGate($actorId)->require(SocialInteractionPermission::Ignore->key());
        $this->requireTargetUser($actorId, $targetId, false);
        $this->interactions->ignore($actorId, $targetId);
    }

    public function unignore(EntityId $actorId, EntityId $targetId): void
    {
        $this->globalGate($actorId)->require(SocialInteractionPermission::Ignore->key());
        $this->requireTargetUser($actorId, $targetId, false);
        $this->interactions->unignore($actorId, $targetId);
    }

    /** @param list<Post> $posts @return list<Post> */
    public function filterIgnoredPosts(EntityId $actorId, array $posts): array
    {
        $ignored = $this->ignoredSet($actorId);
        return array_values(array_filter(
            $posts,
            static fn (Post $post): bool => $post->authorUserId() === null
                || !isset($ignored[$post->authorUserId()->value()]),
        ));
    }

    /** @param list<Thread> $threads @return list<Thread> */
    public function filterIgnoredThreads(EntityId $actorId, array $threads): array
    {
        $ignored = $this->ignoredSet($actorId);
        return array_values(array_filter(
            $threads,
            static fn (Thread $thread): bool => $thread->authorUserId() === null
                || !isset($ignored[$thread->authorUserId()->value()]),
        ));
    }

    /** @return array{0:Post,1:Thread,2:PermissionGate} */
    private function visiblePostContext(EntityId $actorId, EntityId $postId): array
    {
        $post = $this->posts->find($postId);
        if ($post === null || $post->isDeleted() || $post->moderationState() !== PostModerationState::Visible) {
            throw new SocialInteractionException('Content is unavailable.');
        }
        $thread = $this->threads->find($post->threadId());
        if ($thread === null || $thread->moderationState() !== ThreadModerationState::Visible) {
            throw new SocialInteractionException('Content is unavailable.');
        }
        $gate = $this->globalGate($actorId);
        if (!$gate->allows(PermissionKey::fromString('forum.view'), $thread->forumNodeId())) {
            throw new SocialInteractionException('Content is unavailable.');
        }
        return [$post, $thread, $gate];
    }

    private function globalGate(EntityId $actorId): PermissionGate
    {
        return new PermissionGate($this->authorizer, $actorId);
    }

    private function requireTargetUser(EntityId $actorId, EntityId $targetId, bool $mustBeActive = true): void
    {
        if ($actorId->value() === $targetId->value()) {
            throw new SocialInteractionException('Self follow or ignore relationships are not allowed.');
        }
        $target = $this->users->find($targetId);
        if ($target === null || ($mustBeActive && $target->status() !== UserStatus::Active)) {
            throw new SocialInteractionException('Target user is unavailable.');
        }
    }

    private function normalizeNote(?string $note): ?string
    {
        if ($note === null) return null;
        $note = trim($note);
        if ($note === '') return null;
        if (preg_match('//u', $note) !== 1 || strlen($note) > 1000
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $note) === 1) {
            throw new SocialInteractionException('Bookmark note is invalid.');
        }
        return $note;
    }

    /** @return array<string,true> */
    private function ignoredSet(EntityId $actorId): array
    {
        $set = [];
        foreach ($this->interactions->ignoredUserIds($actorId) as $id) {
            $set[$id->value()] = true;
        }
        return $set;
    }
}
