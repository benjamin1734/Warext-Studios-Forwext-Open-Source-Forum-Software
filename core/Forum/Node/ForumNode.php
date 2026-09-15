<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Node;

use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class ForumNode
{
    private function __construct(
        private EntityId $id,
        private ?EntityId $parentId,
        private ForumNodeType $type,
        private string $title,
        private ForumNodeSlug $slug,
        private string $description,
        private int $sortOrder,
        private ForumNodeVisibility $visibility,
        private ?ForumSettings $forumSettings = null,
        private ?string $pageContent = null,
        private ?ForumNodeLinkTarget $linkTarget = null,
        private bool $linkNewWindow = false,
    ) {
        ForumNodeId::assert($this->id);
        if ($this->parentId !== null) {
            ForumNodeId::assert($this->parentId);
            if ($this->parentId->equals($this->id)) {
                throw new InvalidArgumentException('Forum node cannot be its own parent.');
            }
        }

        $title = trim($this->title);
        if ($title === '' || strlen($title) > 150) {
            throw new InvalidArgumentException('Forum node title must contain 1-150 UTF-8 bytes.');
        }
        if (strlen($this->description) > 500) {
            throw new InvalidArgumentException('Forum node description cannot exceed 500 UTF-8 bytes.');
        }
        if ($this->sortOrder < 0 || $this->sortOrder > 4294967295) {
            throw new InvalidArgumentException('Forum node sort order must fit an unsigned 32-bit integer.');
        }

        $this->assertTypePayload();
    }

    public static function category(
        EntityId $id,
        ?EntityId $parentId,
        string $title,
        ForumNodeSlug $slug,
        string $description = '',
        int $sortOrder = 0,
        ForumNodeVisibility $visibility = ForumNodeVisibility::Listed,
    ): self {
        return new self($id, $parentId, ForumNodeType::Category, $title, $slug, $description, $sortOrder, $visibility);
    }

    public static function forum(
        EntityId $id,
        ?EntityId $parentId,
        string $title,
        ForumNodeSlug $slug,
        ForumSettings $settings,
        string $description = '',
        int $sortOrder = 0,
        ForumNodeVisibility $visibility = ForumNodeVisibility::Listed,
    ): self {
        return new self(
            $id,
            $parentId,
            ForumNodeType::Forum,
            $title,
            $slug,
            $description,
            $sortOrder,
            $visibility,
            $settings,
        );
    }

    public static function page(
        EntityId $id,
        ?EntityId $parentId,
        string $title,
        ForumNodeSlug $slug,
        string $pageContent,
        string $description = '',
        int $sortOrder = 0,
        ForumNodeVisibility $visibility = ForumNodeVisibility::Listed,
    ): self {
        return new self(
            $id,
            $parentId,
            ForumNodeType::Page,
            $title,
            $slug,
            $description,
            $sortOrder,
            $visibility,
            null,
            $pageContent,
        );
    }

    public static function link(
        EntityId $id,
        ?EntityId $parentId,
        string $title,
        ForumNodeSlug $slug,
        ForumNodeLinkTarget $target,
        bool $newWindow = false,
        string $description = '',
        int $sortOrder = 0,
        ForumNodeVisibility $visibility = ForumNodeVisibility::Listed,
    ): self {
        return new self(
            $id,
            $parentId,
            ForumNodeType::Link,
            $title,
            $slug,
            $description,
            $sortOrder,
            $visibility,
            null,
            null,
            $target,
            $newWindow,
        );
    }

    public function id(): EntityId
    {
        return $this->id;
    }

    public function parentId(): ?EntityId
    {
        return $this->parentId;
    }

    public function type(): ForumNodeType
    {
        return $this->type;
    }

    public function title(): string
    {
        return trim($this->title);
    }

    public function slug(): ForumNodeSlug
    {
        return $this->slug;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }

    public function visibility(): ForumNodeVisibility
    {
        return $this->visibility;
    }

    public function forumSettings(): ?ForumSettings
    {
        return $this->forumSettings;
    }

    public function pageContent(): ?string
    {
        return $this->pageContent;
    }

    public function linkTarget(): ?ForumNodeLinkTarget
    {
        return $this->linkTarget;
    }

    public function linkNewWindow(): bool
    {
        return $this->linkNewWindow;
    }

    public function canContainChildren(): bool
    {
        return $this->type->canContainChildren();
    }

    private function assertTypePayload(): void
    {
        if ($this->type === ForumNodeType::Forum) {
            if ($this->forumSettings === null || $this->pageContent !== null || $this->linkTarget !== null) {
                throw new InvalidArgumentException('Forum nodes require forum settings and cannot carry page/link payloads.');
            }
            return;
        }

        if ($this->type === ForumNodeType::Page) {
            if ($this->pageContent === null || strlen($this->pageContent) > 100000
                || $this->forumSettings !== null || $this->linkTarget !== null
            ) {
                throw new InvalidArgumentException('Page nodes require bounded page content and cannot carry forum/link payloads.');
            }
            return;
        }

        if ($this->type === ForumNodeType::Link) {
            if ($this->linkTarget === null || $this->forumSettings !== null || $this->pageContent !== null) {
                throw new InvalidArgumentException('Link nodes require a validated target and cannot carry forum/page payloads.');
            }
            return;
        }

        if ($this->forumSettings !== null || $this->pageContent !== null || $this->linkTarget !== null || $this->linkNewWindow) {
            throw new InvalidArgumentException('Category nodes cannot carry forum, page or link payloads.');
        }
    }
}
