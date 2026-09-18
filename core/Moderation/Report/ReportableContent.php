<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Report;

use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class ReportableContent
{
    public function __construct(
        public string $targetType,
        public EntityId $targetId,
        public string $title,
        public ?EntityId $authorUserId = null,
        public ?EntityId $forumNodeId = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9._-]{1,31}$/D', $this->targetType) !== 1) {
            throw new InvalidArgumentException('Reportable content type is invalid.');
        }
        if (trim($this->title) === '' || strlen($this->title) > 255) {
            throw new InvalidArgumentException('Reportable content title must contain 1-255 UTF-8 bytes.');
        }
        if ($this->authorUserId !== null) {
            UserId::assert($this->authorUserId);
        }
    }
}
