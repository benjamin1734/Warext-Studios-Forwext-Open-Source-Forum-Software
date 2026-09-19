<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class AiModerationFeedback
{
    public DateTimeImmutable $createdAt;

    public function __construct(
        public EntityId $feedbackId,
        public EntityId $decisionId,
        public ?EntityId $actorUserId,
        public string $kind,
        public string $note,
        DateTimeImmutable $createdAt,
    ) {
        if (!in_array($this->kind, ['false_positive', 'false_negative'], true)) {
            throw new InvalidArgumentException('AI moderation feedback kind is invalid.');
        }
        $note = trim($this->note);
        if ($note === '' || strlen($note) > 1000 || preg_match('//u', $note) !== 1) {
            throw new InvalidArgumentException('AI moderation feedback note is invalid.');
        }
        $this->createdAt = $createdAt->setTimezone(new DateTimeZone('UTC'));
    }
}
