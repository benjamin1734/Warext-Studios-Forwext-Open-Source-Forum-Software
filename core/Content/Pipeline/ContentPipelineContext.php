<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Pipeline;

use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class ContentPipelineContext
{
    /**
     * @param array<string, scalar|null> $attributes
     */
    public function __construct(
        public EntityId $actorUserId,
        public string $contentType,
        public string $text,
        public int $maxBytes,
        public bool $requiresReview = false,
        public array $attributes = [],
    ) {
        UserId::assert($this->actorUserId);
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $this->contentType) !== 1) {
            throw new InvalidArgumentException('Content pipeline type is invalid.');
        }
        if ($this->maxBytes < 1 || $this->maxBytes > 1_000_000) {
            throw new InvalidArgumentException('Content pipeline maximum byte length is invalid.');
        }
        if (strlen($this->text) > 1_000_000) {
            throw new InvalidArgumentException('Content pipeline input exceeds the hard safety limit.');
        }

        foreach ($this->attributes as $key => $value) {
            if (!is_string($key)
                || preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,63}$/D', $key) !== 1
                || (!is_scalar($value) && $value !== null)
            ) {
                throw new InvalidArgumentException('Content pipeline attributes are invalid.');
            }
        }
    }

    public function withText(string $text): self
    {
        return new self(
            $this->actorUserId,
            $this->contentType,
            $text,
            $this->maxBytes,
            $this->requiresReview,
            $this->attributes,
        );
    }

    public function requiringReview(bool $required = true): self
    {
        return new self(
            $this->actorUserId,
            $this->contentType,
            $this->text,
            $this->maxBytes,
            $required,
            $this->attributes,
        );
    }

    public function withAttribute(string $key, string|int|float|bool|null $value): self
    {
        $attributes = $this->attributes;
        $attributes[$key] = $value;

        return new self(
            $this->actorUserId,
            $this->contentType,
            $this->text,
            $this->maxBytes,
            $this->requiresReview,
            $attributes,
        );
    }
}
