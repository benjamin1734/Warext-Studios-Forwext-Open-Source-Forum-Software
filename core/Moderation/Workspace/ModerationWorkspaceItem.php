<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Workspace;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class ModerationWorkspaceItem
{
    public DateTimeImmutable $updatedAt;

    public function __construct(
        public ModerationWorkspaceSection $section,
        public string $sourceType,
        public string $sourceId,
        public string $title,
        public string $status,
        DateTimeImmutable $updatedAt,
        public ?string $summary = null,
        public ?string $actionPath = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9._-]{1,47}$/D', $this->sourceType) !== 1) {
            throw new InvalidArgumentException('Moderation workspace source type is invalid.');
        }
        if ($this->sourceId === '' || strlen($this->sourceId) > 191
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $this->sourceId) !== 1
        ) {
            throw new InvalidArgumentException('Moderation workspace source id is invalid.');
        }
        if (trim($this->title) === '' || strlen($this->title) > 240) {
            throw new InvalidArgumentException('Moderation workspace title is invalid.');
        }
        if (preg_match('/^[a-z][a-z0-9._-]{0,31}$/D', $this->status) !== 1) {
            throw new InvalidArgumentException('Moderation workspace status is invalid.');
        }
        if ($this->summary !== null && strlen($this->summary) > 1000) {
            throw new InvalidArgumentException('Moderation workspace summary is too long.');
        }
        if ($this->actionPath !== null && (
            $this->actionPath === ''
            || strlen($this->actionPath) > 1000
            || !str_starts_with($this->actionPath, '/')
            || str_starts_with($this->actionPath, '//')
            || preg_match('/[\x00-\x1F\x7F]/', $this->actionPath) === 1
        )) {
            throw new InvalidArgumentException('Moderation workspace action path must be a safe same-origin path.');
        }
        $this->updatedAt = $updatedAt->setTimezone(new DateTimeZone('UTC'));
    }
}
