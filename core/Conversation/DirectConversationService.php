<?php

declare(strict_types=1);

namespace Forwext\Core\Conversation;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Domain\User\Username;
use Forwext\Core\Social\Interaction\SocialInteractionRepository;
use InvalidArgumentException;

final readonly class DirectConversationService
{
    public const USE_PERMISSION = 'conversation.use';

    public function __construct(
        private DirectConversationRepository $conversations,
        private UserRepository $users,
        private SocialInteractionRepository $social,
        private PermissionGate $gate,
    ) {
    }

    /** @return list<DirectConversationSummary> */
    public function inbox(int $limit = 30, int $offset = 0): array
    {
        $this->requireUse();
        return $this->conversations->summaries($this->gate->actorId(), $limit, $offset);
    }

    public function view(EntityId $conversationId, ?DateTimeImmutable $now = null): ?DirectConversationView
    {
        $this->requireUse();
        $actor = $this->gate->actorId();
        $view = $this->conversations->view($actor, $conversationId);
        if ($view === null) {
            return null;
        }
        $this->conversations->markRead($actor, $conversationId, self::utc($now));
        return $view;
    }

    public function start(string $recipientUsername, string $body, ?DateTimeImmutable $now = null): EntityId
    {
        $this->requireUse();
        $actor = $this->gate->actorId();
        $target = $this->users->findByUsername(Username::fromString($recipientUsername));
        if ($target === null || !$target->status()->canAuthenticateNormally()) {
            throw new InvalidArgumentException('Mesaj gönderilecek kullanıcı bulunamadı.');
        }
        if ($actor->equals($target->id())) {
            throw new InvalidArgumentException('Kendine özel mesaj gönderemezsin.');
        }
        $this->assertPairAllowed($actor, $target->id());
        $message = self::body($body);

        $existing = $this->conversations->findPair($actor, $target->id());
        if ($existing !== null) {
            $this->conversations->append($actor, $existing, $message, self::utc($now));
            return $existing;
        }

        return $this->conversations->create($actor, $target->id(), $message, self::utc($now));
    }

    public function reply(EntityId $conversationId, string $body, ?DateTimeImmutable $now = null): DirectMessage
    {
        $this->requireUse();
        $actor = $this->gate->actorId();
        $targetId = $this->conversations->otherParticipant($actor, $conversationId);
        if ($targetId === null) {
            throw new InvalidArgumentException('Özel konuşma bulunamadı.');
        }
        $target = $this->users->find($targetId);
        if ($target === null || !$target->status()->canAuthenticateNormally()) {
            throw new InvalidArgumentException('Mesaj gönderilecek kullanıcı artık kullanılamıyor.');
        }
        $this->assertPairAllowed($actor, $targetId);

        return $this->conversations->append(
            $actor,
            $conversationId,
            self::body($body),
            self::utc($now),
        );
    }

    private function requireUse(): void
    {
        $this->gate->require(PermissionKey::fromString(self::USE_PERMISSION));
    }

    private function assertPairAllowed(EntityId $actor, EntityId $target): void
    {
        if ($this->social->isIgnoring($actor, $target) || $this->social->isIgnoring($target, $actor)) {
            throw new InvalidArgumentException('Yok sayılan kullanıcılarla özel mesajlaşma başlatılamaz.');
        }
    }

    private static function body(string $body): string
    {
        $body = trim($body);
        if ($body === '' || strlen($body) > 10000) {
            throw new InvalidArgumentException('Özel mesaj 1-10000 UTF-8 bayt arasında olmalıdır.');
        }
        return $body;
    }

    private static function utc(?DateTimeImmutable $value): DateTimeImmutable
    {
        return ($value ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
    }
}
