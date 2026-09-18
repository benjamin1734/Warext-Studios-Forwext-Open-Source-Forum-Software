<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Approval;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class ApprovalQueueItem
{
    public DateTimeImmutable $updatedAt;

    public function __construct(
        public string $sourceType,
        public EntityId $sourceId,
        public string $title,
        DateTimeImmutable $updatedAt,
        public ?string $summary = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9._-]{1,47}$/D', $this->sourceType) !== 1) {
            throw new InvalidArgumentException('Approval queue source type is invalid.');
        }
        if (trim($this->title) === '' || strlen($this->title) > 240) {
            throw new InvalidArgumentException('Approval queue title is invalid.');
        }
        if ($this->summary !== null && strlen($this->summary) > 1000) {
            throw new InvalidArgumentException('Approval queue summary is too long.');
        }
        $this->updatedAt = $updatedAt->setTimezone(new DateTimeZone('UTC'));
    }

    public function selection(): ApprovalQueueSelection
    {
        return new ApprovalQueueSelection($this->sourceType, $this->sourceId);
    }
}
