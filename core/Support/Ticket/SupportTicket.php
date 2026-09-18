<?php

declare(strict_types=1);

namespace Forwext\Core\Support\Ticket;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class SupportTicket
{
    public DateTimeImmutable $createdAt;
    public DateTimeImmutable $updatedAt;
    public ?DateTimeImmutable $closedAt;

    public function __construct(
        public EntityId $ticketId,
        public string $categoryKey,
        public ?EntityId $requesterUserId,
        public ?EntityId $assignedUserId,
        public string $subject,
        public SupportTicketPriority $priority,
        public SupportTicketStatus $status,
        public SupportSlaMetadata $sla,
        ?DateTimeImmutable $closedAt,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
        public int $version,
    ) {
        SupportCategory::assertKey($this->categoryKey);
        if ($this->requesterUserId !== null) {
            UserId::assert($this->requesterUserId);
        }
        if ($this->assignedUserId !== null) {
            UserId::assert($this->assignedUserId);
        }
        if (trim($this->subject) === '' || strlen($this->subject) > 200) {
            throw new InvalidArgumentException('Support ticket subject must contain 1-200 UTF-8 bytes.');
        }
        if ($this->version < 1) {
            throw new InvalidArgumentException('Support ticket version must be positive.');
        }

        $utc = new DateTimeZone('UTC');
        $this->closedAt = $closedAt?->setTimezone($utc);
        $this->createdAt = $createdAt->setTimezone($utc);
        $this->updatedAt = $updatedAt->setTimezone($utc);

        if (in_array($this->status, [SupportTicketStatus::Resolved, SupportTicketStatus::Closed], true)
            && $this->sla->resolvedAt === null
        ) {
            throw new InvalidArgumentException('Resolved/closed support tickets require SLA resolution metadata.');
        }
        if ($this->status->isActive() && $this->sla->resolvedAt !== null) {
            throw new InvalidArgumentException('Active support tickets cannot retain resolved SLA metadata.');
        }
        if ($this->status === SupportTicketStatus::Closed && $this->closedAt === null) {
            throw new InvalidArgumentException('Closed support tickets require a closed timestamp.');
        }
        if ($this->status !== SupportTicketStatus::Closed && $this->closedAt !== null) {
            throw new InvalidArgumentException('Only closed support tickets may contain a closed timestamp.');
        }
    }

    public static function generateId(): EntityId
    {
        return EntityId::fromString(bin2hex(random_bytes(16)));
    }

    public function isRequester(EntityId $userId): bool
    {
        return $this->requesterUserId?->equals($userId) ?? false;
    }
}
