<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Editor;

use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Forum\Post\PostModerationState;
use Forwext\Core\Forum\Post\PostPermission;
use Forwext\Core\Forum\Post\PostRepository;
use Forwext\Core\Forum\Thread\ThreadModerationState;
use Forwext\Core\Forum\Thread\ThreadRepository;

final readonly class CrossThreadQuoteService
{
    private const MAX_QUOTE_CHARACTERS = 12000;

    public function __construct(
        private PostRepository $posts,
        private ThreadRepository $threads,
        private UserRepository $users,
    ) {
    }

    public function quote(EntityId $postId, PermissionGate $gate): CrossThreadQuote
    {
        $post = $this->posts->find($postId);
        if ($post === null) {
            throw new QuoteUnavailableException('Quoted post is unavailable.');
        }
        $thread = $this->threads->find($post->threadId());
        if ($thread === null) {
            throw new QuoteUnavailableException('Quoted thread is unavailable.');
        }

        $forumId = $thread->forumNodeId();
        $gate->require(PermissionKey::fromString('forum.view'), $forumId);

        $visible = !$post->isDeleted()
            && $post->moderationState() === PostModerationState::Visible
            && $thread->moderationState() === ThreadModerationState::Visible;
        $owner = $post->authorUserId()?->equals($gate->actorId()) ?? false;
        if (!$visible && !$owner && !$gate->allows(PostPermission::Moderate->key(), $forumId)) {
            throw new QuoteUnavailableException('Quoted post is unavailable.');
        }

        $author = $post->authorUserId() === null ? null : $this->users->find($post->authorUserId());
        $authorLabel = $author === null ? 'Silinmiş kullanıcı' : '@' . $author->username()->display();
        $threadTitle = $thread->title()->value();
        $label = $this->attributeLabel($authorLabel . ' · ' . $threadTitle . ' · #' . $post->position());
        $excerpt = $this->excerpt($post->body()->source());
        $encoded = rtrim(strtr(base64_encode($excerpt), '+/', '-_'), '=');
        $bbCode = '[quote=' . $label . '][plain64=' . $encoded . '][/quote]';

        return new CrossThreadQuote(
            $post->id(),
            $thread->id(),
            $authorLabel,
            $threadTitle,
            $post->position(),
            $bbCode,
        );
    }

    private function excerpt(string $source): string
    {
        $count = preg_match_all('/./us', $source, $unused);
        if ($count === false || $count <= self::MAX_QUOTE_CHARACTERS) {
            return $source;
        }
        preg_match('/\A.{0,' . self::MAX_QUOTE_CHARACTERS . '}/us', $source, $match);
        return ($match[0] ?? '') . '…';
    }

    private function attributeLabel(string $label): string
    {
        $label = preg_replace('/[\]\r\n\x00-\x1F\x7F]+/u', ' ', $label);
        if (!is_string($label)) {
            return 'Alıntı';
        }
        return trim(preg_replace('/\s+/u', ' ', $label) ?? 'Alıntı');
    }
}
