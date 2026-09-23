<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Theme;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class ThemeRevision
{
    public DateTimeImmutable $createdAt;

    public function __construct(
        public EntityId $revisionId,
        public EntityId $themeId,
        public ThemePayload $payload,
        public EntityId $createdBy,
        DateTimeImmutable $createdAt,
    ) {
        foreach ([$this->revisionId, $this->themeId, $this->createdBy] as $id) {
            if (preg_match('/^[a-f0-9]{32}$/D', $id->value()) !== 1) {
                throw new InvalidArgumentException('Theme revision identifiers must be 128-bit lowercase hexadecimal values.');
            }
        }

        $this->createdAt = $createdAt->setTimezone(new DateTimeZone('UTC'));
    }

    public static function generateId(): EntityId
    {
        return EntityId::fromString(bin2hex(random_bytes(16)));
    }
}
